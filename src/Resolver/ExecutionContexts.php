<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

/** Associates nodes with their execution, independently of callback scheduling. */
interface ExecutionContexts
{
    public function associate(Node $node, ExecutionContext $context): void;

    public function of(Node $node): ExecutionContext;
}

function ExecutionContexts(): ExecutionContexts
{
    static $contexts = null;

    $contexts ??= new class implements ExecutionContexts
    {
        /** @var \WeakMap<Node,\WeakReference<ExecutionContext>> */
        private \WeakMap $contexts;

        public function __construct()
        {
            $this->contexts = new \WeakMap;
        }

        public function associate(Node $node, ExecutionContext $context): void
        {
            if (isset($this->contexts[$node]) && $this->contexts[$node]->get() !== $context) {
                throw new \LogicException('A node cannot change its execution context.');
            }

            // Nodes retain their context. The lookup must not keep completed
            // executions alive through a context -> buffer -> node reference cycle.
            $this->contexts[$node] = \WeakReference::create($context);
        }

        public function of(Node $node): ExecutionContext
        {
            $context = isset($this->contexts[$node]) ? $this->contexts[$node]->get() : null;
            if ($context === null) {
                throw new \LogicException('The node has no execution context.');
            }

            return $context;
        }
    };

    return $contexts;
}
