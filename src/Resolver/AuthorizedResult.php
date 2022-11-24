<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugins\AuthorizorPlugin;

final class AuthorizedResult implements Result
{
    private readonly bool $isWorkable;

    private readonly mixed $output;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly Result $result,
        private readonly Node $node,
        private readonly array $context,
        private readonly Plugins $plugins
    )
    {
        $this->isWorkable = (function (): bool {
            return $this->result->isWorkable();
        })();

        $this->output = (function (): mixed {
            if ($this->isWorkable) {
                $authorizorPlugin = new AuthorizorPlugin(
                    nodeType: $this->node->type(),
                    isForCollections: $this->node->isACollection()
                );
        
                if ($this->plugins->contains($authorizorPlugin)) {
                    require_once $this->plugins->directory($authorizorPlugin);
        
                    $authorizorPlugin->callback()($this->node, $this->context);
                }
                
                return $this->result->output();
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