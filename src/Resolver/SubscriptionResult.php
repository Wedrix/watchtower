<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugin;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\SubscriptionPlugin;

trait SubscriptionResult
{
    private Plugin $plugin;

    private bool $isWorkable;

    private mixed $value;

    public function __construct(
        private Node $node,
        private Plugins $plugins
    ) {
        $this->plugin = SubscriptionPlugin(
            fieldName: $this->node->name()
        );

        $this->isWorkable = $this->node->operation() === 'subscription'
            && $this->node->isTopLevel()
            && $this->plugins->contains($this->plugin);

        $this->value = (function (): mixed {
            if ($this->isWorkable) {
                require_once $this->plugins->filePath($this->plugin);

                return $this->plugin->callback()($this->node);
            }

            return null;
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
