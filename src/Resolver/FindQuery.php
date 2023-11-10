<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

final class FindQuery implements Query
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node
    )
    {
        $this->isWorkable = $this->node->isTopLevel() 
            && $this->node->operation() === 'query'
            && $this->query->isWorkable();

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                foreach ($this->node->args() as $idField => $idValue) {
                    $valueAlias = $queryBuilder->reconciledAlias("__{$idField}Value");
        
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()
                                    ->eq("{$queryBuilder->rootAlias()}.$idField", ":$valueAlias")
                    )->setParameter($valueAlias, $idValue);
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