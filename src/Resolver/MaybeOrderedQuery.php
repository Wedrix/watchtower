<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugin\OrderingPlugin;

final class MaybeOrderedQuery implements Query
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node,
        private readonly Plugins $plugins
    )
    {
        $this->isWorkable = $this->query->isWorkable();

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                $queryParams = $this->node->args()['queryParams'] ?? [];
        
                if (!empty($queryParams)) {
                    $ordering = $queryParams['ordering'] ?? [];
        
                    \uasort(
                        $ordering, 
                        /**
                         * @param array{rank:int,params:null|array<string,mixed>} $a
                         * @param array{rank:int,params:null|array<string,mixed>} $b
                         */
                        fn(array $a, array $b): int => $a['rank'] - $b['rank']
                    );
            
                    foreach ($ordering as $orderingName => $_) {
                        $orderingPlugin = new OrderingPlugin(
                            parentNodeType: $this->node->unwrappedType(),
                            orderingName: $orderingName
                        );
        
                        if (!$this->plugins->contains($orderingPlugin)) {
                            throw new \Exception("Invalid query. No ordering plugin exists for '$orderingName'.");
                        }

                        require_once $this->plugins->filePath($orderingPlugin);
    
                        $orderingPlugin->callback()($queryBuilder, $this->node);
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