<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

/**
 * @extends \IteratorAggregate<int,ScalarTypeDefinition>
 */
interface ScalarTypeDefinitions extends \IteratorAggregate
{
    public function contains(
        ScalarTypeDefinition $scalarTypeDefinition
    ): bool;

    public function filePath(
        ScalarTypeDefinition $scalarTypeDefinition
    ): string;

    public function add(
        ScalarTypeDefinition $scalarTypeDefinition
    ): void;
}

function ScalarTypeDefinitions(
    string $directory,
    string $cacheDirectory,
    bool $optimize = false
): ScalarTypeDefinitions
{
    /**
     * @var array<string,array<string,array<string,ScalarTypeDefinitions>>>
     */
    static $instances = [];

    return $instances[$directory][$cacheDirectory][$optimize ? 'true' : 'false'] ??= new class(
        directory: $directory, 
        cacheDirectory: $cacheDirectory, 
        optimize: $optimize
    ) implements ScalarTypeDefinitions {
        public function __construct(
            private readonly string $directory,
            private readonly string $cacheDirectory,
            private readonly bool $optimize
        ){}
    
        public function contains(
            ScalarTypeDefinition $scalarTypeDefinition
        ): bool
        {
            static $filesCache;
    
            if ($this->optimize) {
                $filesCache ??= require $this->cacheDirectory.'/scalar_type_definitions.php';
    
                return \in_array($this->filePath($scalarTypeDefinition), $filesCache);
            }
    
            return \is_file(
                $this->filePath($scalarTypeDefinition)
            );
        }
    
        public function filePath(
            ScalarTypeDefinition $scalarTypeDefinition
        ): string
        {
            return $this->directory.'/'.tableize($scalarTypeDefinition->typeName()).'_type_definition.php';
        }
    
        public function add(
            ScalarTypeDefinition $scalarTypeDefinition
        ): void
        {
            if ($this->contains($scalarTypeDefinition)) {
                throw new \Exception("The type definition for '{$scalarTypeDefinition->typeName()}' already exists.");
            }
    
            file_force_put_contents(
                filename: $this->filePath($scalarTypeDefinition),
                data: $scalarTypeDefinition->template(),
            );
        }
    
        public function getIterator(): \Traversable
        {
            if (!\is_dir($this->directory)) {
                return new \EmptyIterator();
            }
    
            $scalarTypeDefinitionFiles = new \RegexIterator(
                iterator: new \DirectoryIterator($this->directory),
                pattern: '/.+\.php/i',
                mode: \RegexIterator::MATCH
            );
    
            foreach ($scalarTypeDefinitionFiles as $scalarTypeDefinitionFile) {
                $typeName = classify((\explode('_type_definition.php', $scalarTypeDefinitionFile->getBasename()))[0]);
    
                yield GenericScalarTypeDefinition(
                    typeName: $typeName
                );
            }
        }
    };
}