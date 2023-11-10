<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Wedrix\Watchtower\ScalarTypeDefinition\GenericScalarTypeDefinition;

/**
 * @implements \IteratorAggregate<int,GenericScalarTypeDefinition>
 */
final class ScalarTypeDefinitions implements \IteratorAggregate
{
    private readonly string $cacheFile;

    public function __construct(
        private readonly string $directory,
        private readonly string $cacheDirectory,
        private readonly bool $optimize
    )
    {
        if (!\is_dir($this->directory)) {
            throw new \Exception("Invalid plugins directory '{$this->directory}'. Kindly ensure it exists or create it.");
        }

        $this->cacheFile = $this->cacheDirectory.\DIRECTORY_SEPARATOR.'scalar_type_definitions.php';
    }

    public function contains(
        ScalarTypeDefinition $scalarTypeDefinition
    ): bool
    {
        static $filesCache;

        if ($this->optimize) {
            $filesCache ??= require $this->cacheFile;

            return \in_array($this->filePath($scalarTypeDefinition), $filesCache);
        }

        return \file_exists(
            $this->filePath($scalarTypeDefinition)
        );
    }

    public function filePath(
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

        \file_put_contents(
            filename: $this->filePath($scalarTypeDefinition),
            data: $scalarTypeDefinition->template(),
        );
    }

    public function getIterator(): \Traversable
    {
        $scalarTypeDefinitionFiles = new \RegexIterator(
            iterator: new \DirectoryIterator($this->directory),
            pattern: '/.+\.php/i',
            mode: \RegexIterator::MATCH
        );

        foreach ($scalarTypeDefinitionFiles as $scalarTypeDefinitionFile) {
            $typeName = classify((\explode("_type_definition.php", $scalarTypeDefinitionFile->getBasename()))[0]);

            yield new GenericScalarTypeDefinition(
                typeName: $typeName
            );
        }
    }
}