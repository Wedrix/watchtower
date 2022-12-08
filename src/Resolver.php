<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Type\Definition\ResolveInfo;
use Wedrix\Watchtower\Resolver\AuthorizedResult;
use Wedrix\Watchtower\Resolver\EntityManager;
use Wedrix\Watchtower\Resolver\Node;
use Wedrix\Watchtower\Resolver\SmartResult;

final class Resolver
{
    private readonly EntityManager $entityManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        private readonly Plugins $plugins
    )
    {
        $this->entityManager = new EntityManager(
            doctrineEntityManager: $entityManager
        );
    }

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
    ): mixed
    {
        return (
            new AuthorizedResult(
                result: new SmartResult(
                    node: $node = new Node(
                        root: $root,
                        args: $args,
                        context: $context,
                        info: $info
                    ),
                    entityManager: $this->entityManager,
                    plugins: $this->plugins
                ),
                node: $node,
                plugins: $this->plugins
            )
        )
        ->output();
    }
}