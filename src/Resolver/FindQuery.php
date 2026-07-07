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
            $rootAlias = $this->queryBuilder->rootAlias();

            foreach ($this->node->args() as $idField => $idValue) {
                $idValueParameter = $this->queryBuilder->parameterName($idField);

                $this->queryBuilder->andWhere(
                    $this->queryBuilder->expr()
                        ->eq("{$rootAlias}.$idField", ":$idValueParameter")
                )
                    ->setParameter($idValueParameter, $idValue);
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
