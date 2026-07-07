<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support\Fixtures\Reserved;

final class ReservedCursorEntity
{
    private ?int $id = null;

    private string $_cursor = 'shadowed';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function cursorValue(): string
    {
        return $this->_cursor;
    }
}
