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
        ?BatchKey $batchKey = null,
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

    /** @return list<array<string,mixed>> */
    public function parents(BatchKey $batchKey): array;

    public function revision(BatchKey $batchKey): int;

    public function clear(): void;
}

trait _NodeBuffer
{
    /**
     * @var array<Node>
     */
    private array $nodes;

    /**
     * @var array<string,array<int,Node>>
     */
    private array $nodesByBatchKey;

    /** @var array<string,array<string,array<string,mixed>>> */
    private array $parents = [];

    /** @var array<string,int> */
    private array $revisions = [];

    private int $nextRevision = 0;

    public function __construct()
    {
        $this->nodes = [];
        $this->nodesByBatchKey = [];
    }

    public function add(
        Node $node,
        ?BatchKey $batchKey = null,
    ): void {
        $this->nodes[] = $node;
        $key = ($batchKey ?? BatchKey($node))->value();
        $this->nodesByBatchKey[$key][] = $node;
        $parentId = $node->parentId();
        /** @param array<string,mixed> $id */
        $parentKey = static function (array $id) use (&$parentKey): string {
            \ksort($id);
            foreach ($id as &$value) {
                if (\is_array($value)) {
                    $value = $parentKey($value);
                } elseif ($value instanceof \Stringable || \is_scalar($value)) {
                    $value = (string) $value;
                }
            }

            return \serialize($id);
        };
        $parentKey = $parentKey($parentId);
        if (! isset($this->parents[$key][$parentKey])) {
            $this->parents[$key][$parentKey] = $parentId;
            $this->revisions[$key] = ++$this->nextRevision;
        }
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

    public function parents(BatchKey $batchKey): array
    {
        return \array_values($this->parents[$batchKey->value()] ?? []);
    }

    public function revision(BatchKey $batchKey): int
    {
        return $this->revisions[$batchKey->value()] ?? 0;
    }

    public function clear(): void
    {
        $this->nodes = [];
        $this->nodesByBatchKey = [];
        $this->parents = [];
        $this->revisions = [];
    }
}

function NodeBuffer(Node $node): NodeBuffer
{
    return ExecutionContexts()->of($node)->nodeBuffer();
}
