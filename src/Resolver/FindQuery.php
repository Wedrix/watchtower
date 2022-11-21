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
        $this->isWorkable = (function (): bool {
            return $this->query->isWorkable();
        })();

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                $args = $this->node->args();
    
                if (empty($args)) {
                    throw new \Exception("Invalid query. At least one argument is required for this query type.");
                }
    
                foreach ($args as $field => $value) {
                    $valueAlias = $queryBuilder->reconciledAlias("__{$field}Value");
        
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()
                                    ->eq("{$queryBuilder->rootAlias()}.$field", ":$valueAlias")
                    )->setParameter($valueAlias, $value);
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