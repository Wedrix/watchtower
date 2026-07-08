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
     * @return array<int,Node>
     */
    public function matching(
        BatchKey $batchKey
    ): array;

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

        /**
         * @var array<string,array<int,Node>>
         */
        private array $nodesByBatchKey;

        public function __construct()
        {
            $this->nodes = [];
            $this->nodesByBatchKey = [];
        }

        public function add(
            Node $node,
        ): void {
            $this->nodes[] = $node;
            $this->nodesByBatchKey[BatchKey($node)->value()][] = $node;
        }

        public function matching(
            BatchKey $batchKey
        ): array {
            return $this->nodesByBatchKey[$batchKey->value()] ?? [];
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
            $this->nodesByBatchKey = [];
        }
    };
}
