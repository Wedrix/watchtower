<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

trait ParentAssociatedQuery
{
    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private QueryBuilder $queryBuilder,
        private bool $isWorkable
    )
    {
        $this->isWorkable = $this->isWorkable
            && !$this->node->isTopLevel()
            && $this->entityManager->hasEntity(name: $this->node->unwrappedParentType());
    }

    public function builder(): QueryBuilder
    {       
        if ($this->isWorkable) {
            $batchNodes = (function (): array {
                $batchKey = BatchKey(node: $this->node);

                $nodes = [$this->node];

                foreach (NodeBuffer() as $bufferedNode) {
                    $_batchKey = BatchKey(node: $bufferedNode);

                    if ($_batchKey->value() === $batchKey->value()) {
                        $nodes[] = $bufferedNode;
                    }
                }

                return $nodes;
            })();

            $parentEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());

            $parentIds = \array_map(
                static fn (Node $batchNode): array =>
                    \array_reduce(
                        $parentEntity->idFieldNames(),
                        static function (array $parentIdValue, string $parentIdFieldName) use ($parentEntity, $batchNode): array {
                            $rootKey = \in_array($parentIdFieldName, $parentEntity->associationNames()) 
                                ? "__associated_$parentIdFieldName" 
                                : $parentIdFieldName;
                    $parentIdValue[$parentIdFieldName] = $batchNode->root()[$rootKey];

                    return $parentIdValue;
                },
                []
            ), $batchNodes);
            
            $association = $this->node->name();
    
            $rootEntityAlias = $this->queryBuilder->rootAlias();
    
            $parentEntityAlias = '__parent';
    
            if ($parentEntity->associationIsInverseSide($association)) {
                $association = $parentEntity->associationMappedByTargetField($association);

                $idFieldNames = $parentEntity->idFieldNames();

                // Build OR conditions for composite key matching
                $orConditions = $this->queryBuilder->expr()->orX();
                
                foreach ($parentIds as $index => $parentId) {
                    $andConditions = $this->queryBuilder->expr()->andX();
                    
                    foreach ($idFieldNames as $idFieldName) {
                        $paramName = "parent_id_{$index}_{$idFieldName}";
                        $andConditions->add(
                            $this->queryBuilder->expr()
                                ->eq("IDENTITY($rootEntityAlias.$association, '$idFieldName')", ":$paramName")
                        );
                        $this->queryBuilder->setParameter($paramName, $parentId[$idFieldName]);
                    }
                    
                    $orConditions->add($andConditions);
                }
                
                $this->queryBuilder->andWhere($orConditions);

                foreach ($idFieldNames as $idFieldName) {
                    $idNameAlias = "{$parentEntityAlias}_$idFieldName";
                    $this->queryBuilder->addSelect("IDENTITY($rootEntityAlias.$association, '$idFieldName') AS $idNameAlias");
                }
            }
            else {
                $association = $parentEntity->associationInversedByTargetField($association);

                $this->queryBuilder->join(
                    "$rootEntityAlias.$association",
                    $parentEntityAlias
                );

                $idFieldNames = $parentEntity->idFieldNames();

                // Build OR conditions for composite key matching
                $orConditions = $this->queryBuilder->expr()->orX();
                
                foreach ($parentIds as $index => $parentId) {
                    $andConditions = $this->queryBuilder->expr()->andX();
                    
                    foreach ($idFieldNames as $idFieldName) {
                        $paramName = "parent_id_{$index}_{$idFieldName}";
                        $andConditions->add(
                            $this->queryBuilder->expr()
                                ->eq("$parentEntityAlias.$idFieldName", ":$paramName")
                        );
                        $this->queryBuilder->setParameter($paramName, $parentId[$idFieldName]);
                    }
                    
                    $orConditions->add($andConditions);
                }
                
                $this->queryBuilder->andWhere($orConditions);

                foreach ($idFieldNames as $idFieldName) {
                    $idNameAlias = "{$parentEntityAlias}_$idFieldName";
                    
                    if (\in_array($idFieldName, $parentEntity->associationNames())) {
                        $this->queryBuilder->addSelect("IDENTITY($parentEntityAlias.$idFieldName) AS $idNameAlias");
                    } else {
                        $this->queryBuilder->addSelect("$parentEntityAlias.$idFieldName AS $idNameAlias");
                    }
                }
            }
        }

        return $this->queryBuilder;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}