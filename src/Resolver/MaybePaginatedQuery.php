<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait MaybePaginatedQuery
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Node $node,
        private readonly Plugins $plugins
    )
    {
        if ($this->isWorkable) {
            $queryParams = $this->node->args()['queryParams'] ?? [];
    
            if (!empty($queryParams)) {
                $limit = $queryParams['limit'] ?? null;
                $page = $queryParams['page'] ?? null;

                if (!\is_null($page) && \is_null($limit)) {
                    throw new \Exception('Invalid query. The limit parameter is required to paginate a query.');
                }
            
                if (!\is_null($limit)) {
                    $this->queryBuilder->setMaxResults($limit);
            
                    if (!\is_null($page)) {
                        $offset = ($page - 1) * $limit;
            
                        $this->queryBuilder->setFirstResult($offset);
                    }
                }
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