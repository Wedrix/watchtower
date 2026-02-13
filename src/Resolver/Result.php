<?php

namespace Wedrix\Watchtower\Resolver;

interface Result
{
    public function value(): mixed;

    public function isWorkable(): bool;
}
