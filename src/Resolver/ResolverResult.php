<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugin;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ResolverPlugin;

trait ResolverResult
{
    private Plugin $plugin;

    private bool $isWorkable;

    private mixed $output;

    public function __construct(
        private Node $node,
        private Plugins $plugins
    )
    {
        $this->plugin = ResolverPlugin(
            nodeType: $this->node->unwrappedParentType(),
            fieldName: $this->node->name()
        );

        $this->isWorkable = $this->plugins->contains($this->plugin);

        $this->output = (function (): mixed {
            if ($this->isWorkable) {
                require_once $this->plugins->filePath($this->plugin);
                
                return $this->plugin->callback()($this->node);
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