<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use function Wedrix\Watchtower\array_is_list;

interface BatchKey
{
    public function value(): string;
}

function BatchKey(
    Node $node,
): BatchKey {
    /**
     * @var \WeakMap<Node,BatchKey>|null
     */
    static $instances = null;

    if ($instances === null) {
        $instances = new \WeakMap;
    }

    if (! isset($instances[$node])) {
        $instances[$node] = new class($node) implements BatchKey
        {
            private string $value;

            public function __construct(
                Node $node
            ) {
                $this->value = (function () use ($node): string {
                    $args = $node->args();

                    // Recursively sort arrays and associative arrays
                    $sortArgs = static function ($value) use (&$sortArgs) {
                        if (\is_array($value)) {
                            $isList = array_is_list($value);

                            // Check if associative
                            if (! $isList) {
                                \ksort($value);
                            } else {
                                \sort($value);
                            }

                            foreach ($value as &$v) {
                                $v = $sortArgs($v);
                            }
                        }

                        return $value;
                    };

                    $sortedArgs = $sortArgs($args);

                    return $node->unwrappedParentType().'|'.$node->name().'|'.\json_encode($sortedArgs);
                })();
            }

            public function value(): string
            {
                return $this->value;
            }
        };
    }

    return $instances[$node];
}
