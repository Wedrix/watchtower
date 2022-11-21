<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;

final class EntityManager
{
    /**
     * @var array<string,Entity>
     */
    private array $entities = [];

    public function __construct(
        private readonly DoctrineEntityManager $doctrineEntityManager
    ){}

    public function createQueryBuilder(): QueryBuilder
    {
        return new QueryBuilder(
            doctrineEntityManager: $this->doctrineEntityManager
        );
    }

    public function hasEntity(
        string $name
    ): bool
    {
        return array_key_exists($name, $this->entities) 
                || !empty(
                    \array_filter(
                        $this->doctrineEntityManager->getConfiguration()->getMetadataDriverImpl()?->getAllClassNames() 
                            ?? throw new \Exception("Invalid EntityManager. The metadata driver implementation is not set."),
                        fn (string $className) => \str_ends_with($className, "\\$name")
                    )
                );
    }

    public function findEntity(
        string $name
    ): Entity
    {
        return $this->entities[$name] ??= new Entity(
            name: $name,
            entityManager: $this
        );
    }

    public function getConfiguration(): Configuration
    {
        return $this->doctrineEntityManager->getConfiguration();
    }

    public function getClassMetadata(
        string $className
    ): ClassMetadata
    {
        return $this->doctrineEntityManager->getClassMetadata($className);
    }
}