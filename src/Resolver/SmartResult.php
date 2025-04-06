<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait SmartResult
{
    private readonly Result $result;

    private readonly bool $isWorkable;

    private readonly mixed $output;

    public function __construct(
        private readonly Node $node,
        private readonly EntityManager $entityManager,
        private readonly Plugins $plugins
    )
    {
        $this->result = (function (): Result {
            $scalarResult = new class(
                node: $this->node,
                entityManager: $this->entityManager
            ) implements Result {
                use ScalarResult;
            };

            if ($scalarResult->isWorkable()) {
                return $scalarResult;
            }

            $queryResult = new class(
                query: new class(
                    node: $this->node,
                    entityManager: $this->entityManager,
                    plugins: $this->plugins
                ) implements Query {
                    use SmartQuery;
                },
                node: $this->node,
                plugins: $this->plugins
            ) implements Result {
                use QueryResult;
            };

            if ($queryResult->isWorkable()) {
                return $queryResult;
            }

            $mutationResult = new class(
                node: $this->node,
                plugins: $this->plugins
            ) implements Result {
                use MutationResult;
            };

            if ($mutationResult->isWorkable()) {
                return $mutationResult;
            }

            $subscriptionResult = new class(
                node: $this->node,
                plugins: $this->plugins
            ) implements Result {
                use SubscriptionResult;
            };
    
            if ($subscriptionResult->isWorkable()) {
                return $subscriptionResult;
            }

            $resolverResult = new class(
                node: $this->node,
                plugins: $this->plugins
            ) implements Result {
                use ResolverResult;
            };

            if ($resolverResult->isWorkable()) {
                return $resolverResult;
            }

            throw new \Exception("Unable to resolve the node. None of the computed results are workable.");
        })();

        $this->isWorkable = $this->result->isWorkable();

        $this->output = $this->isWorkable ? $this->result->output() : null;
    }

    public function output(): mixed
    {
        return $this->output;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}