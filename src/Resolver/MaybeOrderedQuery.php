<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\OrderingPlugin;

trait MaybeOrderedQuery
{
    public function __construct(
        private Node $node,
        private Plugins $plugins,
        private QueryBuilder $queryBuilder,
        private bool $isWorkable
    ) {
        if ($this->isWorkable) {
            $queryParams = $this->node->args()['queryParams'] ?? [];

            if (! empty($queryParams)) {
                $ordering = $queryParams['ordering'] ?? [];

                \uasort(
                    $ordering,
                    /**
                     * @param  array{rank:int,params:null|array<string,mixed>}  $a
                     * @param  array{rank:int,params:null|array<string,mixed>}  $b
                     */
                    static fn (array $a, array $b): int => $a['rank'] - $b['rank']
                );

                foreach ($ordering as $orderingName => $_) {
                    $orderingPlugin = OrderingPlugin(
                        nodeType: $this->node->unwrappedType(),
                        orderingName: $orderingName
                    );

                    if (! $this->plugins->contains($orderingPlugin)) {
                        throw new \Exception("Invalid query. No ordering plugin exists for '$orderingName'.");
                    }

                    require_once $this->plugins->filePath($orderingPlugin);

                    $orderingPlugin->callback()($this->queryBuilder, $this->node);
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
