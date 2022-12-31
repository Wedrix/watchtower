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
        $this->entityManager = (function (): EntityManager {
            return $this->query->builder()->getEntityManager();
        })();

        $this->isWorkable = (function (): bool {
            return $this->query->isWorkable()
                        && !$this->node->isTopLevel()
                        && $this->entityManager->hasEntity(name: $this->node->unwrappedParentType());
        })();

        $this->queryBuilder = (function(): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                $rootEntity = $this->entityManager->findEntity(name: $this->node->unwrappedType());

                $parentAssociatedEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());

                $parentAssociatedEntityIdValue = (function () use ($parentAssociatedEntity): mixed {
                    $parentAssociatedEntityIdFields = $parentAssociatedEntity->idFields();
        
                    $root = $this->node->root();
        
                    return match(count($parentAssociatedEntityIdFields)) {
                        0 => null,
                        1 => $root[$parentAssociatedEntityIdFields[0]],
                        default => array_map(fn (string $parentAssociatedEntityIdField) => $root[$parentAssociatedEntityIdField], $parentAssociatedEntityIdFields),
                    };
                })() ?? throw new \Exception("Invalid query. The parent node has no resolved id field(s).");
        
                $association = $this->node->name();
        
                $entityAlias = $queryBuilder->rootAlias();
        
                $parentAlias = $queryBuilder->reconciledAlias('__parent');
        
                $parentAssociatedEntityIdParameterAlias = $queryBuilder->reconciledAlias('__parentAssociatedEntityIdValue');
        
                if ($parentAssociatedEntity->associationIsInverside($association)) {
                    $association = $parentAssociatedEntity->associationMappedByTargetField($association);
        
                    if ($rootEntity->associationIsSingleValued($association)) {
                        $queryBuilder->join("$entityAlias.$association", $association);
        
                        $queryBuilder->andWhere(
                            $queryBuilder->expr()
                                        ->eq($association, ":$parentAssociatedEntityIdParameterAlias")
                        );
                    }
                    else {
                        $queryBuilder->andWhere(
                            $queryBuilder->expr()
                                        ->isMemberOf(":$parentAssociatedEntityIdParameterAlias", "$entityAlias.$association")
                        );
                    }
                }
                else {
                    $subquery = $this->entityManager
                                    ->createQueryBuilder()
                                    ->from($parentAssociatedEntity->class(), $parentAlias)
                                    ->join("$parentAlias.$association", $association)
                                    ->where(
                                        $queryBuilder->expr()
                                                    ->eq($parentAlias, ":$parentAssociatedEntityIdParameterAlias")
                                    )
                                    ->select($association);
        
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()
                                    ->in($entityAlias, $subquery->getDQL())
                    );
                }
        
                $queryBuilder->setParameter($parentAssociatedEntityIdParameterAlias, $parentAssociatedEntityIdValue);
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