<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

final class SmartResult implements Result
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
            $scalarResult = new ScalarResult(
                node: $this->node,
                entityManager: $this->entityManager,
                plugins: $this->plugins
            );

            if ($scalarResult->isWorkable()) {
                return $scalarResult;
            }

            $queryResult = new QueryResult(
                query: new SmartQuery(
                    node: $this->node,
                    entityManager: $this->entityManager,
                    plugins: $this->plugins
                ),
                node: $this->node,
                plugins: $this->plugins
            );

            if ($queryResult->isWorkable()) {
                return $queryResult;
            }

            $mutationResult = new MutationResult(
                node: $this->node,
                plugins: $this->plugins
            );

            if ($mutationResult->isWorkable()) {
                return $mutationResult;
            }

            $subscriptionResult = new SubscriptionResult(
                node: $this->node,
                plugins: $this->plugins
            );
    
            if ($subscriptionResult->isWorkable()) {
                return $subscriptionResult;
            }

            $resolverResult = new ResolverResult(
                node: $this->node,
                plugins: $this->plugins
            );

            if ($resolverResult->isWorkable()) {
                return $resolverResult;
            }

            throw new \Exception("Unable to resolve the node. None of the computed results are workable.");
        })();

        $this->isWorkable = (function (): bool {
            return $this->result->isWorkable();
        })();

        $this->output = (function (): mixed {
            return $this->isWorkable ? $this->result->output() : null;
        })();
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