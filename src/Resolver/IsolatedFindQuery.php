<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

trait IsolatedFindQuery
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Node $node
    )
    {
        if ($this->isWorkable) {
            foreach ($this->node->args() as $idField => $idValue) {
                $idValueAlias = $this->queryBuilder->reconciledAlias($idField);
    
                $this->queryBuilder->andWhere(
                    $this->queryBuilder->expr()
                                ->eq("{$this->queryBuilder->rootAlias()}.$idField", ":$idValueAlias")
                )
                ->setParameter($idValueAlias, $idValue);
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