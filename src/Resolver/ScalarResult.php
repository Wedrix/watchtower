<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

trait ScalarResult
{
    private bool $isWorkable;

    private mixed $value;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager
    )
    {
        $this->isWorkable = (
            (\in_array($this->node->name(), \array_keys($this->node->root())))
            || (
                !$this->node->isTopLevel()
                    && $this->entityManager
                            ->hasEntity(
                                name: $entityName = $this->node->unwrappedParentType()
                            )
                    && $this->entityManager
                            ->findEntity(
                                name: $entityName
                            )
                            ->hasEmbeddedField(
                                fieldName: $this->node->name()
                            )
            )
        );

        $this->value = (function (): mixed {
            if ($this->isWorkable) {
                $fieldName = $this->node->name();
        
                $root = $this->node->root();
        
                if (\array_key_exists($fieldName, $root)) {
                    return $root[$fieldName];
                }
        
                $embeddedFields = \array_filter(\array_keys($root), function ($field) use ($fieldName) {
                    return \str_starts_with($field, "$fieldName.");
                });
        
                if (\sizeof($embeddedFields) > 0) {
                    $embeddedField = [];
        
                    foreach ($embeddedFields as $field) {
                        $embeddedFieldName = \substr($field, \strpos($field, ".") + 1);
        
                        $embeddedField[$embeddedFieldName] = $root[$field];
                    }
        
                    return empty(\array_filter(\array_values($embeddedField))) ? null : $embeddedField;
                }
        
                throw new \Exception("Invalid root value. The field '$fieldName' is unset in the resolved root.");
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