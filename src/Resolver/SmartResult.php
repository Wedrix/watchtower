<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

final class SmartResult implements Result
{
    private readonly Result $result;

    private readonly bool $isWorkable;

    /**
     * @var string|int|float|bool|null|array<mixed>
     */
    private readonly string|int|float|bool|null|array $output;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly Node $node,
        private readonly EntityManager $entityManager,
        private readonly array $context,
        private readonly Plugins $plugins
    )
    {
        $this->result = (function (): Result {
            $fielResult = new FieldResult(
                node: $this->node,
                entityManager: $this->entityManager
            );

            if ($fielResult->isWorkable()) {
                return $fielResult;
            }

            $queryResult = new QueryResult(
                query: new SmartQuery(
                    node: $this->node,
                    entityManager: $this->entityManager,
                    context: $this->context,
                    plugins: $this->plugins
                ),
                node: $this->node
            );

            if ($queryResult->isWorkable()) {
                return $queryResult;
            }

            $mutationResult = new MutationResult(
                node: $this->node,
                context: $this->context,
                plugins: $this->plugins
            );

            if ($mutationResult->isWorkable()) {
                return $mutationResult;
            }

            $subscriptionResult = new SubscriptionResult(
                node: $this->node,
                context: $this->context,
                plugins: $this->plugins
            );
    
            if ($subscriptionResult->isWorkable()) {
                return $subscriptionResult;
            }

            $resolverResult = new ResolverResult(
                node: $this->node,
                context: $this->context,
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

        $this->output = (function (): string|int|float|bool|null|array {
            return $this->isWorkable ? $this->result->output() : null;
        })();
    }

    public function output(): string|int|float|bool|null|array
    {
        return $this->output;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}