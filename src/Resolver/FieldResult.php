<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

final class FieldResult implements Result
{
    private readonly bool $isWorkable;

    /**
     * @var string|int|float|bool|null|array<mixed>
     */
    private readonly string|int|float|bool|null|array $output;

    public function __construct(
        private readonly Node $node,
        private readonly EntityManager $entityManager
    )
    {
        $this->isWorkable = (function (): bool {
            return $this->node->isLeafType()
                        || (
                            !$this->node->isTopLevel()
                                && $this->entityManager
                                        ->findEntity(
                                            name: $this->node->parentType()
                                        )
                                        ->hasEmbeddedField(
                                            fieldName: $this->node->fieldName()
                                        )
                        );
        })();

        $this->output = (function (): string|int|float|bool|null|array {
            if ($this->isWorkable) {
                $fieldName = $this->node->fieldName();
        
                $root = $this->node->root();
        
                if (array_key_exists($fieldName, $root)) {
                    return $root[$fieldName];
                }
        
                $embeddedFields = array_filter(array_keys($root), function ($field) use ($fieldName) {
                    return str_starts_with($field, "$fieldName.");
                });
        
                if (sizeof($embeddedFields) > 0) {
                    $embeddedField = [];
        
                    foreach ($embeddedFields as $field) {
                        $embeddedFieldName = substr($field, strpos($field, ".") + 1);
        
                        $embeddedField[$embeddedFieldName] = $root[$field];
                    }
        
                    return $embeddedField;
                }
        
                throw new \Exception("Invalid root value. The field '$fieldName' is unset in the resolved root.");
            }

            return null;
        })();
    }

    public function output(): string|int|float|bool|null|array
    {
        return $this->output;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}