<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

final class Entity
{
    private readonly string $class;

    private readonly ClassMetadata $metadata;

    /**
     * @var array<string>
     */
    private readonly array $fields;

    /**
     * @var array<string>
     */
    private readonly array $idFields;

    /**
     * @var array<string>
     */
    private readonly array $associations;

    public function __construct(
        private readonly string $name,
        private readonly EntityManagerInterface $entityManager
    )
    {
        $this->class = (function (): string {
            $matchingRegisteredClasses = \array_filter(
                $this->entityManager->getConfiguration()->getMetadataDriverImpl()?->getAllClassNames() 
                    ?? throw new \Exception("Invalid EntityManager. The metadata driver implementation is not set."),
                fn (string $className) => \str_ends_with($className, "\\{$this->name}")
            );

            return \array_shift($matchingRegisteredClasses) 
                ?? throw new \Exception("No entity with the name '{$this->name}' exists for the given entity manager instance.");
        })();

        $this->metadata = (function (): ClassMetadata {
            return $this->entityManager->getClassMetadata($this->class);
        })();

        $this->fields = (function (): array {
            return $this->metadata->getFieldNames();
        })();

        $this->idFields = (function (): array {
            return $this->metadata->getIdentifierFieldNames();
        })();

        $this->associations = (function (): array {
            return $this->metadata->getAssociationNames();
        })();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function class(): string
    {
        return $this->class;
    }

    /**
     * @return array<string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<string>
     */
    public function idFields(): array
    {
        return $this->idFields;
    }

    /**
     * @return array<string>
     */
    public function associations(): array
    {
        return $this->associations;
    }

    public function hasEmbeddedField(
        string $fieldName
    ): bool
    {
        return isset($this->metadata->embeddedClasses[$fieldName]);
    }

    public function fieldType(
        string $fieldName
    ): string
    {
        return $this->metadata->getFieldMapping($fieldName)['type'];
    }

    public function associationTargetEntity(
        string $associationName
    ): string
    {
        return $this->metadata->getAssociationMapping($associationName)['targetEntity'];
    }

    public function embeddedFieldClass(
        string $fieldName
    ): string
    {
        return $this->metadata->embeddedClasses[$fieldName]['class'];
    }

    public function associationIsInverside(
        string $associationName
    ): bool
    {
        return $this->metadata->isAssociationInverseSide($associationName);
    }

    public function associationMappedByTargetField(
        string $fieldName
    ): string
    {
        return $this->metadata->getAssociationMappedByTargetField($fieldName);
    }

    public function associationIsSingleValued(
        string $associationName
    ): bool
    {
        return $this->metadata->isSingleValuedAssociation($associationName);
    }

    public function associationIsNullable(
        string $associationName
    ): bool
    {
        return false; // TODO: Implement method
    }
}