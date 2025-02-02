<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

final class SmartQuery implements Query
{
    private readonly Query $query;

    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Node $node,
        private readonly EntityManager $entityManager,
        private readonly Plugins $plugins
    )
    {
        $this->query = (function (): Query {
            $baseQuery = new ConstrainedQuery(
                query: new BaseQuery(
                    node: $this->node,
                    entityManager: $this->entityManager,
                    plugins: $this->plugins
                ),
                node: $this->node,
                plugins: $this->plugins
            );
    
            if ($this->node->isACollection()) {
                $collectionQuery = new MaybeDistinctQuery(
                    query: new MaybeFilteredQuery(
                        query: new MaybeOrderedQuery(
                            query: new MaybePaginatedQuery(
                                query: $baseQuery,
                                node: $this->node
                            ),
                            node: $this->node,
                            plugins: $this->plugins
                        ),
                        node: $this->node,
                        plugins: $this->plugins
                    ),
                    node: $this->node
                );
    
                if ($this->node->isTopLevel()) {
                    return $collectionQuery;
                }

                return new ParentAssociatedQuery(
                    query: $collectionQuery,
                    node: $this->node
                );
            }

            if ($this->node->isTopLevel()) {
                return new FindQuery(
                    query: $baseQuery,
                    node: $this->node
                );
            }
            
            return new ParentAssociatedQuery(
                query: $baseQuery,
                node: $this->node
            );
        })();
        
        $this->isWorkable = $this->query->isWorkable();

        $this->queryBuilder = $this->query->builder();
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