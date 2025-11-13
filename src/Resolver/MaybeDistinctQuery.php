<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

trait MaybeDistinctQuery
{
    public function __construct(
        private Node $node,
        private QueryBuilder $queryBuilder,
        private bool $isWorkable
    )
    {
        if ($this->isWorkable) {
            $queryParams = $this->node->args()['queryParams'] ?? [];
    
            if (!empty($queryParams)) {
                $distinct = $queryParams['distinct'] ?? false;
    
                $this->queryBuilder->distinct($distinct);
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