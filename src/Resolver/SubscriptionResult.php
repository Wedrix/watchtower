<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugins\SubscriptionPlugin;

final class SubscriptionResult implements Result
{
    private readonly SubscriptionPlugin $plugin;

    private readonly bool $isWorkable;

    private readonly mixed $output;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly Node $node,
        private readonly array $context,
        private readonly Plugins $plugins
    )
    {
        $this->plugin = (function (): SubscriptionPlugin {
            return new SubscriptionPlugin(
                fieldName: $this->node->fieldName()
            );
        })();

        $this->isWorkable = (function (): bool {
            return $this->node->operationType() === 'subscription' 
                && $this->node->isTopLevel()
                && $this->plugins->contains($this->plugin);
        })();

        $this->output = (function (): mixed {
            if ($this->isWorkable) {
                require_once $this->plugins->directory($this->plugin);

                return $this->plugin->callback()($this->node, $this->context);
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