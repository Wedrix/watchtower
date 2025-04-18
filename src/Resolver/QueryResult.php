<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ResolverPlugin;

trait QueryResult
{
    private bool $isWorkable;

    private mixed $output;

    public function __construct(
        private Query $query,
        private Node $node,
        private Plugins $plugins
    )
    {
        $this->isWorkable = !$this->plugins
            ->contains(
                ResolverPlugin(
                    nodeType: $this->node->unwrappedParentType(),
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