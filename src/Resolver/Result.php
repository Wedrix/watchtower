<?php

namespace Wedrix\Watchtower\Resolver;

interface Result
{
    public function output(): mixed;

    public function isWorkable(): bool;
}