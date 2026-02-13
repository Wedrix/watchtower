<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManager;
use Wedrix\Watchtower\Entity;

use function Wedrix\Watchtower\Entity;

interface EntityManager
{
    /**
     * @param  string  $name  The name of the entity.
     */
    public function findEntity(
        string $name
    ): Entity;

    /**
     * @param  string  $name  The name of the entity.
     */
    public function hasEntity(
        string $name
    ): bool;

    public function createQueryBuilder(): QueryBuilder;
}

function EntityManager(
    DoctrineEntityManager $doctrineEntityManager
): EntityManager {
    /**
     * @var \WeakMap<DoctrineEntityManager,?EntityManager>
     */
    static $instances = new \WeakMap;

    if (! isset($instances[$doctrineEntityManager])) {
        $instances[$doctrineEntityManager] = null;
    }

    return $instances[$doctrineEntityManager] ??= new class(doctrineEntityManager: $doctrineEntityManager) implements EntityManager
    {
        /**
         * @var array<string,Entity>
         */
        private array $entities = [];

        public function __construct(
            private DoctrineEntityManager $doctrineEntityManager
        ) {}

        public function createQueryBuilder(): QueryBuilder
        {
            return QueryBuilder(
                doctrineQueryBuilder: $this->doctrineEntityManager->createQueryBuilder()
            );
        }

        public function hasEntity(
            string $name
        ): bool {
            return \array_key_exists($name, $this->entities)
                    || ! empty(
                        \array_filter(
                            $this->doctrineEntityManager->getConfiguration()->getMetadataDriverImpl()?->getAllClassNames()
                                ?? throw new \Exception('Invalid EntityManager. The metadata driver implementation is not set.'),
                            static fn (string $className) => \str_ends_with($className, "\\$name")
                        )
                    );
        }

        public function findEntity(
            string $name
        ): Entity {
            return $this->entities[$name] ??= Entity(
                name: $name,
                entityManager: $this->doctrineEntityManager
            );
        }
    };
}
