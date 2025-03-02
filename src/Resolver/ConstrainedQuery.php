<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugin\ConstraintPlugin;
use Wedrix\Watchtower\Plugin\RootConstraintPlugin;
use Wedrix\Watchtower\Plugins;

final class ConstrainedQuery implements Query
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
                $rootConstraintPlugin = new RootConstraintPlugin();

                if ($this->plugins->contains($rootConstraintPlugin)){
                    require_once $this->plugins->filePath($rootConstraintPlugin);
                    
                    $rootConstraintPlugin->callback()($queryBuilder, $this->node);
                }

                $constraintPlugin = new ConstraintPlugin(
                    nodeType: $this->node->unwrappedType()
                );

                if ($this->plugins->contains($constraintPlugin)) {
                    require_once $this->plugins->filePath($constraintPlugin);
                    
                    $constraintPlugin->callback()($queryBuilder, $this->node);
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