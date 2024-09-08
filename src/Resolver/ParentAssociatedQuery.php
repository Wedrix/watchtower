<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

final class ParentAssociatedQuery implements Query
{
    private readonly bool $isWorkable;

    private readonly EntityManager $entityManager;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node
    )
    {
        $this->entityManager = $this->query->builder()->getEntityManager();

        $this->isWorkable = $this->query->isWorkable()
            && !$this->node->isTopLevel()
            && $this->entityManager->hasEntity(name: $this->node->unwrappedParentType());

        $this->queryBuilder = (function(): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                $parentEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());

                $parentIds = \array_reduce(
                    $parentEntity->idFields(),
                    function(array $parentIdValue, string $parentIdField) use($parentEntity): array {
                        $rootKey = \in_array($parentIdField, $parentEntity->associations()) ? "__associated_$parentIdField" : $parentIdField;

                        $parentIdValue[$parentIdField] = $this->node->root()[$rootKey];

                        return $parentIdValue;
                    },
                    []
                );
                
                $association = $this->node->name();
        
                $rootEntityAlias = $queryBuilder->rootAlias();
        
                $parentEntityAlias = '__parent';
        
                if ($parentEntity->associationIsInverseSide($association)) {
                    $association = $parentEntity->associationMappedByTargetField($association);

                    foreach ($parentIds as $idName => $idValue) {
                        $idValueAlias = $queryBuilder->reconciledAlias($idName);

                        $queryBuilder->andWhere(
                            $queryBuilder->expr()
                                        ->eq("IDENTITY($rootEntityAlias.$association,'$idName')", ":$idValueAlias")
                        )
                        ->setParameter($idValueAlias, $idValue);
                    }
                }
                else {
                    $subquery = $this->entityManager
                                    ->createQueryBuilder()
                                    ->from($parentEntity->class(), $parentEntityAlias)
                                    ->join("$parentEntityAlias.$association", $association)
                                    ->select($association);

                    foreach ($parentIds as $idName => $idValue) {
                        $idValueAlias = $queryBuilder->reconciledAlias($idName);
                        
                        $subquery->andWhere(
                            $queryBuilder->expr()
                                        ->eq("$parentEntityAlias.$idName", ":$idValueAlias")
                        );

                        $queryBuilder->setParameter($idValueAlias,$idValue);
                    }
        
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()
                                    ->in($rootEntityAlias, $subquery->getDQL())
                    );
                }
            }
    
            return $queryBuilder;
        })();
    }

    public function builder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}