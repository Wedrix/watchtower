<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

trait FindQuery
{
    public function __construct(
        private Node $node,
        private QueryBuilder $queryBuilder,
        private bool $isWorkable
    ) {
        $this->isWorkable = $this->isWorkable
            && $this->node->isTopLevel()
            && $this->node->operation() === 'query';

        if ($this->isWorkable) {
            $rootEntityAlias = $this->queryBuilder->rootEntityAlias();

            foreach ($this->node->args() as $idField => $idValue) {
                $idValueAlias = $this->queryBuilder->reconciledAlias($idField);

                $this->queryBuilder->andWhere(
                    $this->queryBuilder->expr()
                        ->eq("{$rootEntityAlias}.$idField", ":$idValueAlias")
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
