<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait AuthorizedSmartResult
{
    use SmartResult, AuthorizedResult {
        SmartResult::__construct as private constructSmartResult;
        AuthorizedResult::__construct as private constructAuthorizedResult;
    }

    private bool $isWorkable;

    private mixed $value;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private Plugins $plugins
    )
    {
        $this->constructSmartResult($this->node, $this->entityManager, $this->plugins);

        $this->constructAuthorizedResult($this->node, $this->plugins, $this->value, $this->isWorkable);
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

function AuthorizedSmartResult(
    Node $node,
    EntityManager $entityManager,
    Plugins $plugins
): Result {
    return new class(
        node: $node,
        entityManager: $entityManager,
        plugins: $plugins
    ) implements Result {
        use AuthorizedSmartResult;
    };
}