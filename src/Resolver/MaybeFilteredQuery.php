<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\FilterPlugin;

trait MaybeFilteredQuery
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
                $filters = $queryParams['filters'] ?? [];

                foreach ($filters as $filterName => $_) {
                    $filterPlugin = FilterPlugin(
                        nodeType: $this->node->unwrappedType(),
                        filterName: $filterName
                    );

                    if (! $this->plugins->contains($filterPlugin)) {
                        throw new \Exception("Invalid query. No filter plugin exists for '$filterName'.");
                    }

                    require_once $this->plugins->filePath($filterPlugin);

                    $filterPlugin->callback()($this->queryBuilder, $this->node);
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
