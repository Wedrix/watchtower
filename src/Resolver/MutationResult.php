<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugin\MutationPlugin;

final class MutationResult implements Result
{
    private readonly MutationPlugin $plugin;

    private readonly bool $isWorkable;

    private readonly mixed $output;

    public function __construct(
        private readonly Node $node,
        private readonly Plugins $plugins
    )
    {
        $this->plugin = new MutationPlugin(
            fieldName: $this->node->name()
        );

        $this->isWorkable = $this->node->operation() === 'mutation' 
            && $this->node->isTopLevel()
            && $this->plugins->contains($this->plugin);

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