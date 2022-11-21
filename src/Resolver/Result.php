<?php

namespace Wedrix\Watchtower\Resolver;

interface Result
{
    /**
     * @return string|int|float|bool|null|array<mixed>
     */
    public function output(): string|int|float|bool|null|array;

    public function isWorkable(): bool;
}