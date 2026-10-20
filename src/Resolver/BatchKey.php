<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\Type;

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
                    // Field projections and selector arguments must match before results can be shared.
                    // Normalize maps, but preserve list order because GraphQL input lists are ordered.
                    $normalize = static function ($value) use (&$normalize) {
                        if ($value instanceof Type) {
                            return (string) $value;
                        }

                        if (\is_object($value)) {
                            $value = [
                                'class' => $value::class,
                                'state' => (array) $value,
                            ];
                        }

                        if (\is_array($value)) {
                            if (! array_is_list($value)) {
                                \ksort($value);
                            }

                            foreach ($value as &$v) {
                                $v = $normalize($v);
                            }
                        }

                        return $value;
                    };

                    $signature = [
                        'args' => $normalize($node->args()),
                        'fields' => $normalize($node->concreteFieldsSelection()),
                        'implementors' => $normalize($node->abstractFieldsSelection()),
                    ];

                    return $node->unwrappedParentType().'|'.$node->name().'|'.\json_encode($signature, \JSON_THROW_ON_ERROR);
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
