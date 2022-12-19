<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugins\FilterPlugin;

final class MaybeFilteredQuery implements Query
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node,
        private readonly Plugins $plugins
    )
    {
        $this->isWorkable = (function (): bool {
            return $this->query->isWorkable();
        })();

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                $queryParams = $this->node->args()['queryParams'] ?? [];
        
                if (!empty($queryParams)) {
                    $filters = $queryParams['filters'] ?? [];
        
                    foreach ($filters as $filterName => $_) {
                        $filterPlugin = new FilterPlugin(
                            parentNodeType: $this->node->unwrappedType(),
                            filterName: $filterName
                        );
        
                        if ($this->plugins->contains($filterPlugin)) {
                            require_once $this->plugins->directory($filterPlugin);
                            
                            $filterPlugin->callback()($queryBuilder, $this->node);
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