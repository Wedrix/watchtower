<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use GraphQL\Deferred;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ResolverPlugin;

trait QueryResult
{
    private bool $isWorkable;

    private mixed $value;

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

                $batchKey = BatchKey(node: $this->node);

                if (ResultBuffer()->has($batchKey)) {
                    $batchResult = ResultBuffer()->get($batchKey);
                } else {
                    $doctrineQuery = $this->query->builder()->getQuery();

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

                        $identifierAlias = $this->query->builder()->identifierAlias();

                        // Extract this node's parent ID
                        $parentIds = \array_reduce(
                            $parentEntity->idFieldNames(),
                            function (array $parentIdValue, string $parentIdFieldName) use ($parentEntity, $identifierAlias): array {
                                if (\in_array($parentIdFieldName, $parentEntity->associationFieldNames())) {
                                    $targetEntity = $this->entityManager->findEntity(
                                        name: $parentEntity->associationTargetEntity(
                                            associationName: $parentIdFieldName
                                        )
                                    );

                                    $parentIdValue[$parentIdFieldName] = \array_reduce(
                                        $targetEntity->idFieldNames(),
                                        function (array $associatedIdValue, string $targetIdFieldName) use ($parentIdFieldName, $identifierAlias): array {
                                            $rootKey = "{$identifierAlias}_{$parentIdFieldName}_{$targetIdFieldName}";
                                            $associatedIdValue[$targetIdFieldName] = $this->node->root()[$rootKey];

                                            return $associatedIdValue;
                                        },
                                        []
                                    );
                                } else {
                                    $parentIdValue[$parentIdFieldName] = $this->node->root()[$parentIdFieldName];
                                }

                                return $parentIdValue;
                            },
                            []
                        );

                        // Filter batch to get only results for this node's parent
                        $batchResult = \array_filter($batchResult, function ($resultRecord) use ($parentEntity, $parentEntityAlias, $parentIds): bool {
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
                                                $parentIds[$idFieldName][$targetIdFieldName]
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
                                            $parentIds[$idFieldName]
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
