<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManager;
use GraphQL\Type\Definition\ResolveInfo;
use Wedrix\Watchtower\Resolver\EntityManager;

use function Wedrix\Watchtower\Resolver\AuthorizedSmartResult;
use function Wedrix\Watchtower\Resolver\EntityManager;
use function Wedrix\Watchtower\Resolver\Node;

interface Resolver
{
    /**
     * @param  array<string,mixed>  $root
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>  $context
     */
    public function __invoke(
        array $root,
        array $args,
        array $context,
        ResolveInfo $info
    ): mixed;
}

function Resolver(
    DoctrineEntityManager $doctrineEntityManager,
    Plugins $plugins
): Resolver {
    /**
     * @var \WeakMap<DoctrineEntityManager,\WeakMap<Plugins,?Resolver>>
     */
    static $instances = new \WeakMap;

    if (! isset($instances[$doctrineEntityManager])) {
        $instances[$doctrineEntityManager] = new \WeakMap; // @phpstan-ignore-line
    }

    if (! isset($instances[$doctrineEntityManager][$plugins])) {
        $instances[$doctrineEntityManager][$plugins] = null;
    }

    return $instances[$doctrineEntityManager][$plugins] ??= new class(doctrineEntityManager: $doctrineEntityManager, plugins: $plugins) implements Resolver
    {
        private EntityManager $entityManager;

        public function __construct(
            private DoctrineEntityManager $doctrineEntityManager,
            private Plugins $plugins
        ) {
            $this->entityManager = EntityManager(
                doctrineEntityManager: $this->doctrineEntityManager
            );
        }

        public function __invoke(
            array $root,
            array $args,
            array $context,
            ResolveInfo $info
        ): mixed {
            $result = AuthorizedSmartResult(
                node: Node(
                    root: $root,
                    args: $args,
                    context: $context,
                    info: $info
                ),
                entityManager: $this->entityManager,
                plugins: $this->plugins,
            );

            return $result->value();
        }
    };
}
