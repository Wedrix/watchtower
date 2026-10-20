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
    ?QueryBuilder $queryBuilder = null,
): BatchKey {
    /**
     * @var \WeakMap<Node,BatchKey>|null
     */
    static $instances = null;

    if ($instances === null) {
        $instances = new \WeakMap;
    }

    if ($queryBuilder === null && isset($instances[$node])) {
        return $instances[$node];
    }

    $key = new class($node, $queryBuilder) implements BatchKey
    {
        private string $value;

        public function __construct(
            Node $node,
            ?QueryBuilder $queryBuilder
        ) {
            $querySignature = static function (QueryBuilder $builder): string {
                $parameters = [];
                foreach ($builder->getParameters() as $parameter) {
                    $parameters[$parameter->getName()] = [$parameter->getValue(), $parameter->getType()];
                }
                \ksort($parameters);
                $query = [
                    $builder->getDQL(), $parameters, $builder->getFirstResult(), $builder->getMaxResults(),
                    $builder->cursorOrderings(),
                ];
                try {
                    $encoded = \serialize($query);
                } catch (\Exception) {
                    // Anonymous value objects cannot be serialized by PHP. Retain their private
                    // state and graph references, rather than failing or merging distinct values.
                    $seen = new \SplObjectStorage;
                    $normalize = static function (mixed $value) use (&$normalize, $seen): mixed {
                        if (\is_object($value)) {
                            if ($seen->contains($value)) {
                                return ['reference' => $seen[$value]];
                            }
                            $seen[$value] = $seen->count();
                            $value = [
                                'class' => $value::class,
                                'identity' => $value instanceof \Closure ? \spl_object_id($value) : null,
                                'state' => (array) $value,
                            ];
                        }
                        if (\is_array($value)) {
                            foreach ($value as &$part) {
                                $part = $normalize($part);
                            }
                        }

                        return $value;
                    };
                    $encoded = \serialize($normalize($query));
                }

                return \hash('sha256', $encoded);
            };
            $querySignature = $queryBuilder === null ? '' : $querySignature($queryBuilder);
            $this->value = ($querySignature === '' ? '' : $querySignature.'|').(function () use ($node): string {
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

    if ($queryBuilder === null) {
        $instances[$node] = $key;
    }

    return $key;
}
