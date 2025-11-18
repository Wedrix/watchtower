<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait SmartResult
{
    use ScalarResult, QueryResult, MutationResult, SubscriptionResult, ResolverResult {
        ScalarResult::__construct as private _constructScalarResult;
        QueryResult::__construct as private _constructQueryResult;
        MutationResult::__construct as private _constructMutationResult;
        SubscriptionResult::__construct as private _constructSubscriptionResult;
        ResolverResult::__construct as private _constructResolverResult;
    }

    private bool $isWorkable;

    private mixed $value;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private Plugins $plugins
    )
    {
        $this->_constructScalarResult(
            node: $this->node,
            entityManager: $this->entityManager,
        );

        if ($this->isWorkable) {
            return;
        }

        $this->_constructQueryResult(
            query: SmartQuery(
                node: $this->node,
                entityManager: $this->entityManager,
                plugins: $this->plugins,
            ),
            node: $this->node,
            plugins: $this->plugins,
            entityManager: $this->entityManager,
        );

        if ($this->isWorkable) {
            return;
        }

        $this->_constructMutationResult(
            node: $this->node,
            plugins: $this->plugins
        );

        if ($this->isWorkable) {
            return;
        }

        $this->_constructSubscriptionResult(
            node: $this->node,
            plugins: $this->plugins
        );

        if ($this->isWorkable) {
            return;
        }

        $this->_constructResolverResult(
            node: $this->node,
            plugins: $this->plugins
        );

        if ($this->isWorkable) {
            return;
        }

        throw new \Exception("Unable to resolve the node. None of the result strategies were workable.");
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}