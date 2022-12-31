<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugin\AuthorizorPlugin;

final class AuthorizedResult implements Result
{
    private readonly bool $isWorkable;

    private readonly mixed $output;

    public function __construct(
        private readonly Result $result,
        private readonly Node $node,
        private readonly Plugins $plugins
    )
    {
        $this->isWorkable = (function (): bool {
            return $this->result->isWorkable();
        })();

        $this->output = (function (): mixed {
            if ($this->isWorkable) {
                $authorizorPlugin = new AuthorizorPlugin(
                    nodeType: $this->node->unwrappedType(),
                    isForCollections: $this->node->isACollection()
                );
        
                if ($this->plugins->contains($authorizorPlugin)) {
                    require_once $this->plugins->directory($authorizorPlugin);
        
                    $authorizorPlugin->callback()($this->result, $this->node);
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