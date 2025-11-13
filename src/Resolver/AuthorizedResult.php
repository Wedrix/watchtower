<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Deferred;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\AuthorizorPlugin;
use function Wedrix\Watchtower\RootAuthorizorPlugin;

trait AuthorizedResult
{
    public function __construct(
        private Node $node,
        private Plugins $plugins,
        private mixed $value,
        private bool $isWorkable
    ){
        if ($this->isWorkable) {
            $authorize = function (): void {
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
            };

            if ($this->value instanceof Deferred) {
                $result = $this;

                $this->value = $this->value->then(
                    function ($resolvedValue) use ($authorize, $result): mixed {
                        $result->value = $resolvedValue;
                        
                        \Closure::fromCallable($authorize)->call($result);

                        return $resolvedValue;
                    }
                );
            } else {
                $authorize();
            }
        }
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