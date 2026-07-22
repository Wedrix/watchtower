<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait HydrationQuery
{
    use BaseQuery, ConstrainedQuery, MaybeFilteredQuery {
        BaseQuery::__construct as private _constructBaseQuery;
        ConstrainedQuery::__construct as private _constructConstrainedQuery;
        MaybeFilteredQuery::__construct as private _constructMaybeFilteredQuery;
    }

    private bool $isWorkable;

    private QueryBuilder $queryBuilder;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private Plugins $plugins,
        bool $applyFilters
    ) {
        $this->_constructBaseQuery(
            node: $this->node,
            entityManager: $this->entityManager,
            plugins: $this->plugins
        );

        $this->_constructConstrainedQuery(
            node: $this->node,
            plugins: $this->plugins,
            queryBuilder: $this->queryBuilder,
            isWorkable: $this->isWorkable
        );

        if ($applyFilters && $this->node->isACollection()) {
            $this->_constructMaybeFilteredQuery(
                node: $this->node,
                plugins: $this->plugins,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );
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

function HydrationQuery(
    Node $node,
    EntityManager $entityManager,
    Plugins $plugins,
    bool $applyFilters = true
): Query {
    return new class(node: $node, entityManager: $entityManager, plugins: $plugins, applyFilters: $applyFilters) implements Query
    {
        use HydrationQuery;
    };
}
