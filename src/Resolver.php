<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface as DoctrineEntityManager;
use GraphQL\Type\Definition\ResolveInfo;
use Wedrix\Watchtower\Resolver\EntityManager;
use Wedrix\Watchtower\Resolver\ExecutionContext;

use function Wedrix\Watchtower\Resolver\AuthorizedNode;
use function Wedrix\Watchtower\Resolver\AuthorizedSmartResult;
use function Wedrix\Watchtower\Resolver\EntityManager;

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
    Plugins $plugins,
    ExecutionContext $executionContext
): Resolver {
    return new class(doctrineEntityManager: $doctrineEntityManager, plugins: $plugins, executionContext: $executionContext) implements Resolver
    {
        private EntityManager $entityManager;

        public function __construct(
            private DoctrineEntityManager $doctrineEntityManager,
            private Plugins $plugins,
            private ExecutionContext $executionContext
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
            // GraphQL mutation fields execute serially. Each must read its own writes.
            if ($info->operation->operation === 'mutation' && \count($info->path) === 1) {
                $this->executionContext->clearResults();
            }

            $result = AuthorizedSmartResult(
                node: AuthorizedNode(
                    root: $root,
                    args: $args,
                    context: $context,
                    info: $info,
                    entityManager: $this->entityManager,
                    plugins: $this->plugins,
                    executionContext: $this->executionContext
                ),
                entityManager: $this->entityManager,
                plugins: $this->plugins
            );

            return $result->value();
        }
    };
}
