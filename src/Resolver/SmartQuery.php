<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait SmartQuery
{
    use BaseQuery, ConstrainedQuery, MaybePaginatedQuery, MaybeOrderedQuery, MaybeFilteredQuery, MaybeDistinctQuery, ParentAssociatedQuery, FindQuery {
        BaseQuery::__construct as constructBase;
        ConstrainedQuery::__construct as constrain;
        MaybePaginatedQuery::__construct as paginate;
        MaybeOrderedQuery::__construct as order;
        MaybeFilteredQuery::__construct as filter;
        MaybeDistinctQuery::__construct as deduplicate;
        ParentAssociatedQuery::__construct as associateParent;
        FindQuery::__construct as find;
    }

    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Node $node,
        private readonly EntityManager $entityManager,
        private readonly Plugins $plugins
    )
    {
        $this->constructBase($node, $entityManager, $plugins);

        $this->constrain($node, $plugins);

        if ($node->isACollection()) {
            $this->filter($node, $plugins);

            $this->deduplicate($node);

            $this->order($node, $plugins);

            $this->paginate($node, $plugins);

            if (!$node->isTopLevel()) {
                $this->associateParent($node, $entityManager);
            }
        }

        if (!$node->isACollection()){
            if ($node->isTopLevel()) {
                $this->find($node);
            }
    
            if (!$node->isTopLevel()){
                $this->associateParent($node, $entityManager);
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