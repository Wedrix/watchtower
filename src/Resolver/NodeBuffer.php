<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

/**
 * @extends \IteratorAggregate<int,Node>
 */
interface NodeBuffer extends \IteratorAggregate
{
    public function add(
        Node $node,
    ): void;

    /**
     * @return \Traversable<int,Node>
     */
    public function getIterator(): \Traversable;

    public function clear(): void;
}

function NodeBuffer(): NodeBuffer
{
    static $instance;

    return $instance ??= new class implements NodeBuffer
    {
        /**
         * @var array<Node>
         */
        private array $nodes;

        public function __construct()
        {
            $this->nodes = [];
        }

        public function add(
            Node $node,
        ): void {
            $this->nodes[] = $node;
        }

        public function getIterator(): \Traversable
        {
            foreach ($this->nodes as $node) {
                yield $node;
            }
        }

        public function clear(): void
        {
            $this->nodes = [];
        }
    };
}
