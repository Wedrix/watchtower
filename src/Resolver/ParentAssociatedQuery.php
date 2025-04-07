<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

trait ParentAssociatedQuery
{
    private bool $isWorkable;

    private QueryBuilder $queryBuilder;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
    )
    {
        $this->isWorkable = $this->isWorkable
            && !$this->node->isTopLevel()
            && $this->entityManager->hasEntity(name: $this->node->unwrappedParentType());

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
    
            $rootEntityAlias = $this->queryBuilder->rootAlias();
    
            $parentEntityAlias = '__parent';
    
            if ($parentEntity->associationIsInverseSide($association)) {
                $association = $parentEntity->associationMappedByTargetField($association);

                foreach ($parentIds as $idName => $idValue) {
                    $idValueAlias = $this->queryBuilder->reconciledAlias($idName);

                    $this->queryBuilder->andWhere(
                        $this->queryBuilder->expr()
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
                    $idValueAlias = $this->queryBuilder->reconciledAlias($idName);
                    
                    $subquery->andWhere(
                        $this->queryBuilder->expr()
                                    ->eq("$parentEntityAlias.$idName", ":$idValueAlias")
                    );

                    $this->queryBuilder->setParameter($idValueAlias,$idValue);
                }
    
                $this->queryBuilder->andWhere(
                    $this->queryBuilder->expr()
                                ->in($rootEntityAlias, $subquery->getDQL())
                );
            }
        }
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