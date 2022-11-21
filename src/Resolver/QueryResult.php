<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

final class QueryResult implements Result
{
    private readonly bool $isWorkable;

    /**
     * @var string|int|float|bool|null|array<mixed>
     */
    private readonly string|int|float|bool|null|array $output;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node
    )
    {
        $this->isWorkable = (function (): bool {
            return $this->query->isWorkable() 
                && $this->node->operationType() === 'query';
        })();

        $this->output = (function (): string|int|float|bool|null|array {
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

    public function output(): string|int|float|bool|null|array
    {
        return $this->output;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}