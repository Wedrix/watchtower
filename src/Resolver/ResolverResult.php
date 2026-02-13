<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Deferred;
use Wedrix\Watchtower\Plugin;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ResolverPlugin;

trait ResolverResult
{
    private Plugin $plugin;

    private bool $isWorkable;

    private mixed $value;

    public function __construct(
        private Node $node,
        private Plugins $plugins
    ) {
        $this->plugin = ResolverPlugin(
            nodeType: $this->node->unwrappedParentType(),
            fieldName: $this->node->name()
        );

        $this->isWorkable = $this->plugins->contains($this->plugin);

        $this->value = (function (): mixed {
            if (! $this->isWorkable) {
                return null;
            }

            NodeBuffer()->add(
                node: $this->node
            );

            return new Deferred(function (): mixed {
                require_once $this->plugins->filePath($this->plugin);

                return $this->plugin->callback()($this->node);
            });
        })();
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}
