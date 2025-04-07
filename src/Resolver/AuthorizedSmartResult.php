<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

trait AuthorizedSmartResult
{
    use SmartResult, AuthorizedResult {
        SmartResult::__construct as construct;
        AuthorizedResult::__construct as authorize;
    }

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private Plugins $plugins
    )
    {
        $this->construct($this->node, $this->entityManager, $this->plugins);
        $this->authorize($this->node, $this->plugins);
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }

    public function output(): mixed
    {
        return $this->output;
    }
}