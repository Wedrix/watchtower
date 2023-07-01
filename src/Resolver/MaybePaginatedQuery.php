<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

final class MaybePaginatedQuery implements Query
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node
    )
    {
        $this->isWorkable = $this->query->isWorkable();

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                $queryParams = $this->node->args()['queryParams'] ?? [];
        
                if (!empty($queryParams)) {
                    $limit = $queryParams['limit'] ?? null;
                    $page = $queryParams['page'] ?? null;

                    if (!\is_null($page) && \is_null($limit)) {
                        throw new \Exception('Invalid query. The limit parameter is required to paginate a query.');
                    }
                
                    if (!\is_null($limit)) {
                        $queryBuilder->setMaxResults($limit);
                
                        if (!\is_null($page)) {
                            $offset = ($page - 1) * $limit;
                
                            $queryBuilder->setFirstResult($offset);
                        }
                    }
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