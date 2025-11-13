<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

interface Entity
{
    public function name(): string;

    public function class(): string;

    /**
     * @return array<string>
     */
    public function fieldNames(): array;

    /**
     * @return array<string>
     */
    public function idFieldNames(): array;

    /**
     * @return array<string>
     */
    public function associationNames(): array;

    public function hasEmbeddedField(
        string $fieldName
    ): bool;

    public function fieldType(
        string $fieldName
    ): string;

    public function fieldIsNullable(
        string $fieldName
    ): bool;

    public function associationTargetEntity(
        string $associationName
    ): string;

    public function embeddedFieldClass(
        string $fieldName
    ): string;

    public function associationIsInverseSide(
        string $associationName
    ): bool;

    public function associationMappedByTargetField(
        string $fieldName
    ): string;

    public function associationInversedByTargetField(
        string $fieldName
    ): string;

    public function associationIsSingleValued(
        string $associationName
    ): bool;

    public function associationIsNullable(
        string $associationName
    ): bool;
}

function Entity(
    string $name,
    EntityManagerInterface $entityManager
): Entity
{
    /**
     * @var \WeakMap<EntityManagerInterface,array<string,Entity>>
     */
    static $instances = new \WeakMap();
    
    if (!isset($instances[$entityManager])) {
        $instances[$entityManager] = [];
    }

    return $instances[$entityManager][$name] ??= new class(
        name: $name,
        entityManager: $entityManager
    ) implements Entity {
        private string $class;
    
        private ClassMetadata $metadata;
    
        /**
         * @var array<string>
         */
        private array $fieldNames;
    
        /**
         * @var array<string>
         */
        private array $idFieldNames;
    
        /**
         * @var array<string>
         */
        private array $associationNames;
    
        public function __construct(
            private string $name,
            private EntityManagerInterface $entityManager
        )
        {
            $this->class = (function (): string {
                $matchingRegisteredClasses = \array_filter(
                    $this->entityManager->getConfiguration()->getMetadataDriverImpl()?->getAllClassNames() 
                        ?? throw new \Exception('Invalid EntityManager. The metadata driver implementation is not set.'),
                    fn (string $className) => \str_ends_with($className, "\\{$this->name}")
                );
    
                return \array_shift($matchingRegisteredClasses) 
                    ?? throw new \Exception("No entity with the name '{$this->name}' exists for the given entity manager instance.");
            })();
    
            $this->metadata = $this->entityManager->getClassMetadata($this->class);
    
            $this->fieldNames = $this->metadata->getFieldNames();
    
            $this->idFieldNames = $this->metadata->getIdentifierFieldNames();
    
            $this->associationNames = $this->metadata->getAssociationNames();
        }
    
        public function name(): string
        {
            return $this->name;
        }
    
        public function class(): string
        {
            return $this->class;
        }
    
        public function fieldNames(): array
        {
            return $this->fieldNames;
        }
    
        public function idFieldNames(): array
        {
            return $this->idFieldNames;
        }
    
        public function associationNames(): array
        {
            return $this->associationNames;
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
    
        public function fieldIsNullable(
            string $fieldName
        ): bool
        {
            return $this->metadata->isNullable($fieldName);
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
    
        public function associationIsInverseSide(
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

        public function associationInversedByTargetField(
            string $fieldName
        ): string
        {
            return $this->metadata->associationMappings[$fieldName]['inversedBy'];
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
    };
}