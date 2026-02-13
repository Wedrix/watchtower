<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait SmartQuery
{
    use BaseQuery, ConstrainedQuery, FindQuery, MaybeDistinctQuery, MaybeFilteredQuery, MaybeOrderedQuery, MaybePaginatedQuery, ParentAssociatedQuery {
        BaseQuery::__construct as private _constructBaseQuery;
        ConstrainedQuery::__construct as private _constructConstrainedQuery;
        MaybePaginatedQuery::__construct as private _constructMaybePaginatedQuery;
        MaybeOrderedQuery::__construct as private _constructMaybeOrderedQuery;
        MaybeFilteredQuery::__construct as private _constructMaybeFilteredQuery;
        MaybeDistinctQuery::__construct as private _constructMaybeDistinctQuery;
        FindQuery::__construct as private _constructFindQuery;
        ParentAssociatedQuery::__construct as private _constructParentAssociatedQuery;
        ParentAssociatedQuery::builder as private _parentAssociatedBuilder;
    }

    private bool $isWorkable;

    private QueryBuilder $queryBuilder;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private Plugins $plugins
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

        if ($this->node->isACollection()) {
            $this->_constructMaybeFilteredQuery(
                node: $this->node,
                plugins: $this->plugins,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );

            $this->_constructMaybeDistinctQuery(
                node: $this->node,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );

            $this->_constructMaybeOrderedQuery(
                node: $this->node,
                plugins: $this->plugins,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );

            $this->_constructMaybePaginatedQuery(
                node: $this->node,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );
        } elseif ($this->node->isTopLevel()) {
            $this->_constructFindQuery(
                node: $this->node,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );
        }

        if (! $this->node->isTopLevel()) {
            $this->_constructParentAssociatedQuery(
                node: $this->node,
                entityManager: $this->entityManager,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );
        }
    }

    public function builder(): QueryBuilder
    {
        if (! $this->node->isTopLevel()) {
            return $this->_parentAssociatedBuilder();
        }

        return $this->queryBuilder;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}

function SmartQuery(
    Node $node,
    EntityManager $entityManager,
    Plugins $plugins
): Query {
    return new class(node: $node, entityManager: $entityManager, plugins: $plugins) implements Query
    {
        use SmartQuery;
    };
}
