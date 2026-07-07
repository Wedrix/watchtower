<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use function Wedrix\Watchtower\reservedFieldResultKey;

trait ScalarResult
{
    private bool $isWorkable;

    private mixed $value;

    /** @phpstan-impure */
    public function __construct(
        private Node $node,
        private EntityManager $entityManager
    ) {
        $isManagedReservedEntityField = false;

        if (
            ! $this->node->isTopLevel()
            && $this->entityManager->hasEntity(name: $this->node->unwrappedParentType())
        ) {
            $parentEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());

            $isManagedReservedEntityField = \in_array(
                $this->node->name(),
                \array_keys($parentEntity->reservedFields()),
                true
            );
        }

        $this->isWorkable = $isManagedReservedEntityField || (
            (\in_array($this->node->name(), \array_keys($this->node->root())))
            || (
                ! $this->node->isTopLevel()
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

        $this->value = (function () use ($isManagedReservedEntityField): mixed {
            if ($this->isWorkable) {
                $fieldName = $this->node->name();

                $root = $this->node->root();

                if ($isManagedReservedEntityField) {
                    $reservedFieldKey = reservedFieldResultKey($fieldName);

                    return \array_key_exists($reservedFieldKey, $root)
                        ? $root[$reservedFieldKey]
                        : null;
                }

                if (\array_key_exists($fieldName, $root)) {
                    return $root[$fieldName];
                }

                $embeddedFields = \array_filter(\array_keys($root), function ($field) use ($fieldName) {
                    return \str_starts_with($field, "$fieldName.");
                });

                if (count($embeddedFields) > 0) {
                    $embeddedField = [];

                    foreach ($embeddedFields as $field) {
                        $embeddedFieldName = \substr($field, \strpos($field, '.') + 1);

                        $embeddedField[$embeddedFieldName] = $root[$field];
                    }

                    return empty(\array_filter(\array_values($embeddedField))) ? null : $embeddedField;
                }

                throw new InvalidRootValueScalarResultException("Invalid root value. The field '$fieldName' is unset in the resolved root.");
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
