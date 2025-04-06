<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ConstraintPlugin;
use function Wedrix\Watchtower\RootConstraintPlugin;

trait ConstrainedQuery
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Node $node,
        private readonly Plugins $plugins
    )
    {
        if ($this->isWorkable) {
            $rootConstraintPlugin = RootConstraintPlugin();

            if ($this->plugins->contains($rootConstraintPlugin)){
                require_once $this->plugins->filePath($rootConstraintPlugin);
                
                $rootConstraintPlugin->callback()($this->queryBuilder, $this->node);
            }

            $constraintPlugin = ConstraintPlugin(
                nodeType: $this->node->unwrappedType()
            );

            if ($this->plugins->contains($constraintPlugin)) {
                require_once $this->plugins->filePath($constraintPlugin);
                
                $constraintPlugin->callback()($this->queryBuilder, $this->node);
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