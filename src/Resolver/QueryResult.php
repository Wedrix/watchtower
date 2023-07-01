<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugin\ResolverPlugin;

final class QueryResult implements Result
{
    private readonly bool $isWorkable;

    private readonly mixed $output;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node,
        private readonly Plugins $plugins
    )
    {
        $this->isWorkable = !$this->plugins
            ->contains(
                new ResolverPlugin(
                    parentNodeType: $this->node->unwrappedParentType(),
                    fieldName: $this->node->name()
                )
            )
            && $this->query->isWorkable();

        $this->output = (function (): mixed {
            if ($this->isWorkable) {
                $doctrineQuery = $this->query->builder()->getQuery();
        
                if ($this->node->isACollection()) {
                    return $doctrineQuery->getResult();
                }
        
                if ($this->node->isNullable()) {
                    return $doctrineQuery->getOneOrNullResult();
                }
        
                return $doctrineQuery->getSingleResult();
            }

            return null;
        })();
    }

    public function output(): mixed
    {
        return $this->output;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}