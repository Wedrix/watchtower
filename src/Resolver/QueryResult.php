<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

final class QueryResult implements Result
{
    private readonly bool $isWorkable;

    private readonly mixed $output;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node
    )
    {
        $this->isWorkable = (function (): bool {
            return $this->query->isWorkable() 
                && $this->node->operationType() === 'query';
        })();

        $this->output = (function (): mixed {
            if ($this->isWorkable) {
                $doctrineQuery = $this->query->builder()->getQuery();
        
                if ($this->node->isACollection()) {
                    return $doctrineQuery->getResult();
                }
        
                if ($this->node->isNullable()) {
                    return $doctrineQuery->getOneOrNullResult();
                }
        
                return $doctrineQuery->getSingleResult();
            }

            return null;
        })();
    }

    public function output(): mixed
    {
        return $this->output;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}