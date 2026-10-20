<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/** The temporary state belonging to one GraphQL execution. */
interface ExecutionContext
{
    public function nodeBuffer(): NodeBuffer;

    public function resultBuffer(): ResultBuffer;

    /** @return array{fields:array<string,mixed>,implementors:array<string,mixed>} */
    public function fieldsSelection(ResolveInfo $info): array;

    public function clearResults(): void;

    public function clear(): void;
}

function ExecutionContext(): ExecutionContext
{
    return new class implements ExecutionContext
    {
        private NodeBuffer $nodes;

        private ResultBuffer $results;

        /** @var array<string,array{fields:array<string,mixed>,implementors:array<string,mixed>}> */
        private array $selections = [];

        public function __construct()
        {
            $this->nodes = new class implements NodeBuffer
            {
                use _NodeBuffer;
            };
            $this->results = new class($this->nodes) implements ResultBuffer
            {
                use _ResultBuffer;
            };
        }

        public function nodeBuffer(): NodeBuffer
        {
            return $this->nodes;
        }

        public function resultBuffer(): ResultBuffer
        {
            return $this->results;
        }

        public function fieldsSelection(ResolveInfo $info): array
        {
            // Variables and the schema are fixed for this execution. Parsed documents
            // may be reused by other executions, which have their own selection cache.
            $parts = [Type::getNamedType($info->returnType)->name()];
            foreach ($info->fieldNodes as $node) {
                $parts[] = (string) \spl_object_id($node);
            }
            $key = \implode('|', $parts);

            return $this->selections[$key] ??= nodeFieldsSelection($info);
        }

        public function clearResults(): void
        {
            $this->nodes->clear();
            $this->results->clear();
        }

        public function clear(): void
        {
            $this->clearResults();
            $this->selections = [];
        }
    };
}
