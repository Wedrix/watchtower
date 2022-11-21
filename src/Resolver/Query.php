<?php

namespace Wedrix\Watchtower\Resolver;

interface Query
{
    public function builder(): QueryBuilder;

    public function isWorkable(): bool;
}