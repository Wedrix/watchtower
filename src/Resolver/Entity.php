<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

final class Entity
{
    private readonly string $class;

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
        private readonly EntityManager $entityManager
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

        $this->fields = (function (): array {
            $classMetadata = $this->entityManager->getClassMetadata($this->class);
    
            return $classMetadata->getFieldNames();
        })();

        $this->idFields = (function (): array {
            $classMetadata = $this->entityManager->getClassMetadata($this->class);
    
            return $classMetadata->getIdentifierFieldNames();
        })();

        $this->associations = (function (): array {
            $classMetadata = $this->entityManager->getClassMetadata($this->class);
    
            return $classMetadata->getAssociationNames();
        })();
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
        $classMetadata = $this->entityManager->getClassMetadata($this->class);

        return isset($classMetadata->embeddedClasses[$fieldName]);
    }
}