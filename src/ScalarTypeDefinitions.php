<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use function Wedrix\Watchtower\string\classify;
use function Wedrix\Watchtower\string\tableize;

/**
 * @implements \IteratorAggregate<int,ScalarTypeDefinition>
 */
final class ScalarTypeDefinitions implements \IteratorAggregate
{
    public function __construct(
        private readonly string $directory
    ){}

    public function contains(
        ScalarTypeDefinition $scalarTypeDefinition
    ): bool
    {
        return file_exists(
            $this->directory($scalarTypeDefinition)
        );
    }

    public function directory(
        ScalarTypeDefinition $scalarTypeDefinition
    ): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.tableize($scalarTypeDefinition->typeName())."_type_definition.php";
    }

    public function add(
        ScalarTypeDefinition $scalarTypeDefinition
    ): void
    {
        if ($this->contains($scalarTypeDefinition)) {
            throw new \Exception("The type definition for '{$scalarTypeDefinition->typeName()}' already exists.");
        }

        file_put_contents(
            filename: $this->directory($scalarTypeDefinition),
            data: $scalarTypeDefinition->template(),
        );
    }

    public function getIterator(): \Traversable
    {
        foreach (new \DirectoryIterator($this->directory) as $directory) {
            yield new ScalarTypeDefinition(
                typeName: classify(
                    ($dirElements = explode(\DIRECTORY_SEPARATOR, explode("_type_definition.php", $directory->getPath())[0]))[count($dirElements) - 1]
                )
            );
        }
    }
}