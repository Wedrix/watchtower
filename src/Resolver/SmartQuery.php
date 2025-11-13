<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait SmartQuery
{
    use BaseQuery, ConstrainedQuery, MaybePaginatedQuery, MaybeOrderedQuery, MaybeFilteredQuery, MaybeDistinctQuery, FindQuery, ParentAssociatedQuery {
        BaseQuery::__construct as private constructBaseQuery;
        ConstrainedQuery::__construct as private constructConstrainedQuery;
        MaybePaginatedQuery::__construct as private constructMaybePaginatedQuery;
        MaybeOrderedQuery::__construct as private constructMaybeOrderedQuery;
        MaybeFilteredQuery::__construct as private constructMaybeFilteredQuery;
        MaybeDistinctQuery::__construct as private constructMaybeDistinctQuery;
        FindQuery::__construct as private constructFindQuery;
        ParentAssociatedQuery::__construct as private constructParentAssociatedQuery;
        ParentAssociatedQuery::builder as private parentAssociatedBuilder;
    }

    private bool $isWorkable;

    private QueryBuilder $queryBuilder;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private Plugins $plugins
    )
    {
        $this->constructBaseQuery(
            node: $this->node, 
            entityManager: $this->entityManager, 
            plugins: $this->plugins
        );

        $this->constructConstrainedQuery(
            node: $this->node,
            plugins: $this->plugins,
            queryBuilder: $this->queryBuilder,
            isWorkable: $this->isWorkable
        );

        if ($this->node->isACollection()) {
            $this->constructMaybeFilteredQuery(
                node: $this->node,
                plugins: $this->plugins,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );

            $this->constructMaybeDistinctQuery(
                node: $this->node,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );

            $this->constructMaybeOrderedQuery(
                node: $this->node, 
                plugins: $this->plugins,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );

            $this->constructMaybePaginatedQuery(
                node: $this->node,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );
        } 
        else if ($this->node->isTopLevel()) {
            $this->constructFindQuery(
                node: $this->node,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );
        }

        if (!$this->node->isTopLevel()) {
            $this->constructParentAssociatedQuery(
                node: $this->node,
                entityManager: $this->entityManager,
                queryBuilder: $this->queryBuilder,
                isWorkable: $this->isWorkable
            );
        }
    }

    public function builder(): QueryBuilder
    {
        if (!$this->node->isTopLevel()) {
            return $this->parentAssociatedBuilder();
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
    return new class(
        node: $node,
        entityManager: $entityManager,
        plugins: $plugins
    ) implements Query {
        use SmartQuery;
    };
}