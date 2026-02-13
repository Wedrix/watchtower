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
        BatchKey $batchKey,
    ): bool;

    public function get(
        BatchKey $batchKey,
    ): mixed;

    public function clear(): void;
}

function ResultBuffer(): ResultBuffer
{
    static $instance;

    return $instance ??= new class implements ResultBuffer
    {
        /**
         * @var array<string,mixed>
         */
        private array $keys;

        public function __construct()
        {
            $this->keys = [];
        }

        public function add(
            BatchKey $batchKey,
            mixed $batchResult
        ): void {
            $this->keys[$batchKey->value()] = $batchResult;
        }

        public function has(
            BatchKey $batchKey,
        ): bool {
            return isset($this->keys[$batchKey->value()]);
        }

        public function get(
            BatchKey $batchKey,
        ): mixed {
            if (! $this->has($batchKey)) {
                throw new \RuntimeException(
                    message: 'ResultBuffer does not contain batchKey: '.$batchKey->value()
                );
            }

            return $this->keys[$batchKey->value()];
        }

        public function clear(): void
        {
            $this->keys = [];
        }
    };
}
