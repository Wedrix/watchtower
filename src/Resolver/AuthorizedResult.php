<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\AuthorizorPlugin;
use function Wedrix\Watchtower\RootAuthorizorPlugin;

trait AuthorizedResult
{
    private bool $isWorkable;

    private mixed $output;

    public function __construct(
        private Node $node,
        private Plugins $plugins
    )
    {
        if ($this->isWorkable) {
            $rootAuthorizorPlugin = RootAuthorizorPlugin();

            if ($this->plugins->contains($rootAuthorizorPlugin)) {
                require_once $this->plugins->filePath($rootAuthorizorPlugin);
    
                $rootAuthorizorPlugin->callback()($this, $this->node);
            }

            $authorizorPlugin = AuthorizorPlugin(
                nodeType: $this->node->unwrappedType(),
                isForCollections: $this->node->isACollection()
            );
    
            if ($this->plugins->contains($authorizorPlugin)) {
                require_once $this->plugins->filePath($authorizorPlugin);
    
                $authorizorPlugin->callback()($this, $this->node);
            }
        }
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