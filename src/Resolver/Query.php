<?php

namespace Wedrix\Watchtower\Resolver;

interface Query
{
    /** The prepared retrieval, before expanding the parent identifiers. */
    public function batchKey(): BatchKey;

    public function builder(): QueryBuilder;

    public function isWorkable(): bool;
}
