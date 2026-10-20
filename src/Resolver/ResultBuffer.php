<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

interface ResultBuffer
{
    public function add(
        BatchKey $batchKey,
        mixed $batchResult
    ): void;

    public function has(
        BatchKey $batchKey
    ): bool;

    public function get(
        BatchKey $batchKey
    ): mixed;

    public function clear(): void;
}

trait _ResultBuffer
{
    /**
     * @var array<string,mixed>
     */
    private array $keys;

    /**
     * @var array<string,int>
     */
    private array $parentRevisions;

    public function __construct(private NodeBuffer $nodeBuffer)
    {
        $this->keys = [];
        $this->parentRevisions = [];
    }

    public function add(
        BatchKey $batchKey,
        mixed $batchResult
    ): void {
        $this->keys[$batchKey->value()] = $batchResult;
        $this->parentRevisions[$batchKey->value()] = $this->nodeBuffer->revision($batchKey);
    }

    public function has(
        BatchKey $batchKey
    ): bool {
        // Deeper branches can discover more parents after this batch was queried.
        // Refresh only for new parents, including when the earlier result was empty.
        return \array_key_exists($batchKey->value(), $this->keys)
            && $this->parentRevisions[$batchKey->value()] === $this->nodeBuffer->revision($batchKey);
    }

    public function get(
        BatchKey $batchKey
    ): mixed {
        if (! $this->has($batchKey)) {
            throw new MissingResultBufferBatchKeyException(
                message: 'ResultBuffer does not contain batchKey: '.$batchKey->value()
            );
        }

        return $this->keys[$batchKey->value()];
    }

    public function clear(): void
    {
        $this->keys = [];
        $this->parentRevisions = [];
    }
}

function ResultBuffer(Node $node): ResultBuffer
{
    return ExecutionContexts()->of($node)->resultBuffer();
}
