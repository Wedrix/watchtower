<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Type\Definition\ResolveInfo;
use Wedrix\Watchtower\Resolver\AuthorizedSmartResult;
use Wedrix\Watchtower\Resolver\EntityManager;
use Wedrix\Watchtower\Resolver\Result;

use function Wedrix\Watchtower\Resolver\EntityManager;
use function Wedrix\Watchtower\Resolver\Node;

interface Resolver
{
    /**
     * @param array<string,mixed> $root
     * @param array<string,mixed> $args
     * @param array<string,mixed> $context
     */
    public function __invoke(
        array $root,
        array $args,
        array $context,
        ResolveInfo $info
    ): mixed;
}

function Resolver(
    EntityManagerInterface $entityManager,
    Plugins $plugins
): Resolver
{
    /**
     * @var \WeakMap<EntityManagerInterface,\WeakMap<Plugins,Resolver>>
     */
    static $instances = [];

    return $instances[$entityManager][$plugins] ??= new class(
        entityManager: $entityManager,
        plugins: $plugins
    ) implements Resolver {
        private readonly EntityManager $entityManager;
    
        public function __construct(
            EntityManagerInterface $entityManager,
            private readonly Plugins $plugins
        )
        {
            $this->entityManager = EntityManager(
                doctrineEntityManager: $entityManager
            );
        }
    
        public function __invoke(
            array $root,
            array $args,
            array $context,
            ResolveInfo $info
        ): mixed
        {
            $result = new class(
                node: Node(
                    root: $root,
                    args: $args,
                    context: $context,
                    info: $info
                ),
                entityManager: $this->entityManager,
                plugins: $this->plugins
            ) implements Result {
                use AuthorizedSmartResult;
            };
            
            return $result->output();
        }
    };
}