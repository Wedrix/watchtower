<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugins\FilterPlugin;

final class MaybeFilteredQuery implements Query
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly Query $query,
        private readonly Node $node,
        private readonly array $context,
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
        
                    foreach ($filters as $filter => $_) {
                        $filterPlugin = new FilterPlugin(
                            nodeType: $this->node->type(),
                            filter: $filter
                        );
        
                        if ($this->plugins->contains($filterPlugin)) {
                            require_once $this->plugins->directory($filterPlugin);
                            
                            $filterPlugin->callback()($queryBuilder, $this->node, $this->context);
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