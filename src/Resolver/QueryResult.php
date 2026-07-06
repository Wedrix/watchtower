<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query as DoctrineQuery;
use GraphQL\Deferred;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ResolverPlugin;
use function Wedrix\Watchtower\SearchResolverPlugin;

trait QueryResult
{
    private bool $isWorkable;

    private mixed $value;

    /** @phpstan-impure */
    public function __construct(
        private Query $query,
        private Node $node,
        private Plugins $plugins,
        private EntityManager $entityManager,
    ) {
        $this->isWorkable = ! $this->plugins
            ->contains(
                ResolverPlugin(
                    nodeType: $this->node->unwrappedParentType(),
                    fieldName: $this->node->name()
                )
            )
            && ! (
                isset($this->node->args()['queryParams']['search'])
                && $this->plugins
                    ->contains(
                        SearchResolverPlugin(
                            nodeType: $this->node->unwrappedType()
                        )
                    )
            )
            && $this->query->isWorkable();

        $this->value = (function (): mixed {
            if (! $this->isWorkable) {
                return null;
            }

            NodeBuffer()->add(
                node: $this->node
            );

            return new Deferred(function (): mixed {
                $batchResult = [];

                $queryParams = $this->node->args()['queryParams'] ?? [];
                $limit = $queryParams['limit'] ?? null;
                $before = $queryParams['before'] ?? null;

                $batchKey = BatchKey(node: $this->node);

                if (ResultBuffer()->has($batchKey)) {
                    $batchResult = ResultBuffer()->get($batchKey);
                } else {
                    $doctrineQuery = $this->query->builder()->getQuery();

                    // Nested collection pagination must happen per parent row,
                    // so we need to partition the batched result by the selected parent id aliases
                    if (! $this->node->isTopLevel() && $this->node->isACollection() && $limit !== null) {
                        $rootEntity = $this->entityManager->findEntity(name: $this->node->unwrappedType());

                        if (
                            \count($rootEntity->idFieldNames()) !== 1
                            || \in_array($rootEntity->idFieldNames()[0], $rootEntity->associationFieldNames())
                        ) {
                            throw new UnsupportedPerParentPaginationIdentifierQueryResultException('Per-parent pagination currently requires a single-column scalar child identifier.');
                        }

                        $parentEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());
                        $parentEntityAlias = $this->query->builder()->parentEntityAlias();

                        $parentIdResultAliases = [];

                        foreach ($parentEntity->idFieldNames() as $idFieldName) {
                            if (\in_array($idFieldName, $parentEntity->associationFieldNames())) {
                                $targetEntity = $this->entityManager->findEntity(
                                    name: $parentEntity->associationTargetEntity(
                                        associationName: $idFieldName
                                    )
                                );

                                foreach ($targetEntity->idFieldNames() as $targetIdFieldName) {
                                    $parentIdResultAliases[] = "{$parentEntityAlias}_{$idFieldName}_{$targetIdFieldName}";
                                }

                                continue;
                            }

                            $parentIdResultAliases[] = "{$parentEntityAlias}_$idFieldName";
                        }

                        $page = (int) ($queryParams['page'] ?? 1);
                        $perParentLimit = (int) $limit;
                        $firstResult = ($page - 1) * $perParentLimit;

                        $doctrineQuery->setHint(DoctrineQuery::HINT_CUSTOM_OUTPUT_WALKER, PerParentPaginationOutputWalker::class);
                        $doctrineQuery->setHint(
                            PerParentPaginationOutputWalker::HINT_PARTITION_RESULT_ALIASES,
                            $parentIdResultAliases
                        );
                        $doctrineQuery->setHint(
                            PerParentPaginationOutputWalker::HINT_TIE_BREAKER_RESULT_ALIAS,
                            $rootEntity->idFieldNames()[0]
                        );
                        $doctrineQuery->setHint(PerParentPaginationOutputWalker::HINT_FIRST_RESULT, $firstResult);
                        $doctrineQuery->setHint(PerParentPaginationOutputWalker::HINT_MAX_RESULTS, $perParentLimit);
                    }

                    $batchResult = $doctrineQuery->getResult();

                    ResultBuffer()->add(
                        batchKey: $batchKey,
                        batchResult: $batchResult
                    );
                }

                // Filter the batch to only include results for the current node (only if node has a parent)
                $filteredBatch = (function () use ($batchResult): array {
                    if (! $this->node->isTopLevel()) {
                        $parentEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());

                        $parentEntityAlias = $this->query->builder()->parentEntityAlias();

                        // Extract this node's parent ID
                        $parentId = $this->node->parentId();

                        // Filter batch to get only results for this node's parent
                        $batchResult = \array_filter($batchResult, function ($resultRecord) use ($parentEntity, $parentEntityAlias, $parentId): bool {
                            // Check parent ID fields that were added to the query
                            foreach ($parentEntity->idFieldNames() as $idFieldName) {
                                if (\in_array($idFieldName, $parentEntity->associationFieldNames())) {
                                    $targetEntity = $this->entityManager->findEntity(
                                        name: $parentEntity->associationTargetEntity(
                                            associationName: $idFieldName
                                        )
                                    );

                                    foreach ($targetEntity->idFieldNames() as $targetIdFieldName) {
                                        $parentIdKey = "{$parentEntityAlias}_{$idFieldName}_{$targetIdFieldName}";
                                        if (
                                            ! isset($resultRecord[$parentIdKey])
                                            || ! $this->entityManager->identifiersMatch(
                                                $resultRecord[$parentIdKey],
                                                $parentId[$idFieldName][$targetIdFieldName]
                                            )
                                        ) {
                                            return false;
                                        }
                                    }
                                } else {
                                    $parentIdKey = "{$parentEntityAlias}_$idFieldName";
                                    if (
                                        ! isset($resultRecord[$parentIdKey])
                                        || ! $this->entityManager->identifiersMatch(
                                            $resultRecord[$parentIdKey],
                                            $parentId[$idFieldName]
                                        )
                                    ) {
                                        return false;
                                    }
                                }
                            }

                            return true;
                        });

                        $batchResult = \array_values($batchResult);
                    }

                    return $batchResult;
                })();

                $value = $filteredBatch;

                if ($before !== null) {
                    $value = \array_reverse($value);
                }

                if (! $this->node->isACollection()) {
                    if (count($filteredBatch) > 1) {
                        throw new NonUniqueResultException;
                    }

                    if (! $this->node->isNullable() && (count($filteredBatch) == 0)) {
                        throw new NoResultException;
                    }

                    $value = $filteredBatch[0] ?? null;
                }

                return $value;
            });
        })();
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}
