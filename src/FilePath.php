<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

final class FilePath
{
    private function __construct(
        private readonly string $value
    ){}

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @param array<string> $arguments
     */
    public static function __callStatic(
        string $name, 
        array $arguments
    ): self
    {
        $value = \trim($name);

        if (empty($value)) {
            throw new \Exception('Invalid FilePath! The value cannot be empty.');
        }

        $value = \realpath($value); // Canonicalize

        if (($value === false) || !\is_file($value)) {
            throw new \Exception('Invalid FilePath!');
        }

        return new self(
            value: $value
        );
    }
}