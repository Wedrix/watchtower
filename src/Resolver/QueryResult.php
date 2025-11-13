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
    )
    {
        $this->isWorkable = !$this->plugins
            ->contains(
                ResolverPlugin(
                    nodeType: $this->node->unwrappedParentType(),
                    fieldName: $this->node->name()
                )
            )
            && $this->query->isWorkable();

        $this->value = (function (): mixed {
            if (!$this->isWorkable) {
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
                }
                else {
                    $doctrineQuery = $this->query->builder()->getQuery();

                    $batchResult = $doctrineQuery->getResult();

                    ResultBuffer()->add(
                        batchKey: $batchKey,
                        batchResult: $batchResult
                    );
                }

                // Filter the batch to only include results for the current node (only if node has a parent)
                $filteredBatch = (function () use ($batchResult): array {
                    if (!$this->node->isTopLevel()) {
                        $parentEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());
                        
                        // Extract this node's parent ID
                        $parentIds = \array_reduce(
                            $parentEntity->idFieldNames(),
                            function (array $parentIdValue, string $parentIdFieldName) use ($parentEntity): array {
                                $rootKey = \in_array($parentIdFieldName, $parentEntity->associationNames()) 
                                    ? "__associated_$parentIdFieldName" 
                                    : $parentIdFieldName;
                                $parentIdValue[$parentIdFieldName] = $this->node->root()[$rootKey];
                                return $parentIdValue;
                            },
                            []
                        );
                        
                        // Filter batch to get only results for this node's parent
                        $batchResult = \array_filter($batchResult, function ($item) use ($parentEntity, $parentIds): bool {
                            // Check parent ID fields that were added to the query
                            foreach ($parentEntity->idFieldNames() as $idFieldName) {
                                $parentIdKey = "__parent_$idFieldName";
                                if (!isset($item[$parentIdKey]) || $item[$parentIdKey] !== $parentIds[$idFieldName]) {
                                    return false;
                                }
                            }
                            return true;
                        });
                        
                        $batchResult = \array_values($batchResult);
                    }

                    return $batchResult;
                })();

                $value = $filteredBatch;

                if (!$this->node->isACollection()) {
                    if (count($filteredBatch) > 1) {
                        throw new NonUniqueResultException();
                    }

                    if (!$this->node->isNullable() && (count($filteredBatch) == 0)) {
                        throw new NoResultException();
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