<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Deferred;
use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ResultAuthorizorPlugin;
use function Wedrix\Watchtower\RootResultAuthorizorPlugin;

trait AuthorizedResult
{
    public function __construct(
        private Node $node,
        private Plugins $plugins,
        private mixed $value,
        private bool $isWorkable
    ) {
        if ($this->isWorkable) {
            $authorize = function (): void {
                $resultAuthorizorPlugin = ResultAuthorizorPlugin(
                    nodeType: $this->node->unwrappedType(),
                    isForCollections: false
                );
                $hasResultAuthorizorPlugin = $this->plugins->contains($resultAuthorizorPlugin);

                if (
                    $hasResultAuthorizorPlugin
                    && $this->node->isACollection()
                    && $this->value instanceof \Traversable
                ) {
                    $this->value = \iterator_to_array($this->value, false);
                }

                $rootResultAuthorizorPlugin = RootResultAuthorizorPlugin();

                if ($this->plugins->contains($rootResultAuthorizorPlugin)) {
                    require_once $this->plugins->filePath($rootResultAuthorizorPlugin);

                    $rootResultAuthorizorPlugin->callback()($this, $this->node);
                }

                if (! $this->node->isACollection()) {
                    if ($hasResultAuthorizorPlugin) {
                        require_once $this->plugins->filePath($resultAuthorizorPlugin);

                        $resultAuthorizorPlugin->callback()($this, $this->node);
                    }

                    return;
                }

                $collectionResultAuthorizorPlugin = ResultAuthorizorPlugin(
                    nodeType: $this->node->unwrappedType(),
                    isForCollections: true
                );

                if ($this->plugins->contains($collectionResultAuthorizorPlugin)) {
                    require_once $this->plugins->filePath($collectionResultAuthorizorPlugin);

                    $collectionResultAuthorizorPlugin->callback()($this, $this->node);
                }

                if (! $hasResultAuthorizorPlugin || ! \is_array($this->value)) {
                    return;
                }

                require_once $this->plugins->filePath($resultAuthorizorPlugin);

                $authorizeRow = function (mixed $value) use ($resultAuthorizorPlugin): void {
                    $result = new class(value: $value) implements Result
                    {
                        public function __construct(
                            private mixed $value
                        ) {}

                        public function value(): mixed
                        {
                            return $this->value;
                        }

                        public function isWorkable(): bool
                        {
                            return true;
                        }
                    };

                    $resultAuthorizorPlugin->callback()($result, $this->node);
                };

                foreach ($this->value as $key => $value) {
                    if ($value instanceof Deferred) {
                        $this->value[$key] = $value->then(
                            static function (mixed $resolvedValue) use ($authorizeRow): mixed {
                                if ($resolvedValue !== null) {
                                    $authorizeRow($resolvedValue);
                                }

                                return $resolvedValue;
                            }
                        );

                        continue;
                    }

                    if ($value !== null) {
                        $authorizeRow($value);
                    }
                }
            };

            if ($this->value instanceof Deferred) {
                $result = $this;

                $this->value = $this->value->then(
                    static function ($resolvedValue) use ($authorize, $result): mixed {
                        $result->value = $resolvedValue;

                        \Closure::fromCallable($authorize)->call($result);

                        return $result->value;
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
