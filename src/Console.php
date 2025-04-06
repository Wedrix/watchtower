<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Language\Parser;
use GraphQL\Utils\AST;
use GraphQL\Utils\SchemaPrinter;

interface Console
{
    public function scalarTypeDefinitions(): ScalarTypeDefinitions;

    public function plugins(): Plugins;

    public function generateSchema(): void;

    public function updateSchema(): void;

    public function addScalarTypeDefinition(
        string $typeName
    ): void;

    public function addConstraintPlugin(
        string $nodeType
    ): void;

    public function addRootConstraintPlugin(): void;

    public function addFilterPlugin(
        string $nodeType,
        string $filterName
    ): void;

    public function addOrderingPlugin(
        string $nodeType,
        string $orderingName
    ): void;

    public function addSelectorPlugin(
        string $nodeType,
        string $fieldName
    ): void;

    public function addResolverPlugin(
        string $nodeType,
        string $fieldName
    ): void;

    public function addAuthorizorPlugin(
        string $nodeType,
        bool $isForCollections
    ): void;

    public function addRootAuthorizorPlugin(): void;

    public function addMutationPlugin(
        string $fieldName
    ): void;

    public function addSubscriptionPlugin(
        string $fieldName
    ): void;

    public function generateCache(): void;
}
    
/**
 * @api
 * 
 * @param EntityManagerInterface $entityManager The Doctrine entityManager instance.
 * @param string $schemaFileDirectory The directory of the schema file.
 * @param string $schemaFileName The name of the schema file.
 * @param string $pluginsDirectory The plugin functions' directory.
 * @param string $scalarTypeDefinitionsDirectory The scalar types' definitions' directory.
 * @param string $cacheDirectory The directory for storing cache files.
 */
function Console(
    EntityManagerInterface $entityManager,
    string $schemaFileDirectory,
    string $schemaFileName,
    string $pluginsDirectory,
    string $scalarTypeDefinitionsDirectory,
    string $cacheDirectory
): Console
{
    /**
     * @var \WeakMap<EntityManagerInterface,array<string,array<string,array<string,array<string,array<string,Console>>>>
     */
    static $instances = new \WeakMap();

    return $instances[$entityManager][$schemaFileDirectory][$schemaFileName][$pluginsDirectory][$scalarTypeDefinitionsDirectory][$cacheDirectory] ??= new class(
        entityManager: $entityManager,
        schemaFileDirectory: $schemaFileDirectory,
        schemaFileName: $schemaFileName,
        pluginsDirectory: $pluginsDirectory,
        scalarTypeDefinitionsDirectory: $scalarTypeDefinitionsDirectory,
        cacheDirectory: $cacheDirectory
    ) implements Console {
        private readonly Plugins $plugins;
    
        private readonly ScalarTypeDefinitions $scalarTypeDefinitions;

        public function __construct(
            private readonly EntityManagerInterface $entityManager,
            private readonly string $schemaFileDirectory,
            private readonly string $schemaFileName,
            private readonly string $pluginsDirectory,
            private readonly string $scalarTypeDefinitionsDirectory,
            private readonly string $cacheDirectory
        )
        {
            $this->plugins = Plugins(
                directory: $this->pluginsDirectory,
                cacheDirectory: $this->cacheDirectory,
                optimize: false
            );
    
            $this->scalarTypeDefinitions = ScalarTypeDefinitions(
                directory: $this->scalarTypeDefinitionsDirectory,
                cacheDirectory: $this->cacheDirectory,
                optimize: false
            );
        }
    
        public function scalarTypeDefinitions(): ScalarTypeDefinitions
        {
            return $this->scalarTypeDefinitions;
        }
    
        public function plugins(): Plugins
        {
            return $this->plugins;
        }
    
        public function generateSchema(): void
        {
            if (\is_file($schemaFile = $this->schemaFileDirectory.'/'.$this->schemaFileName)) {
                throw new \Exception("The schema '$schemaFile' already exists. Kindly either update it using the console command or delete the file to proceed regenerating it.");
            }
    
            file_force_put_contents(
                filename: $schemaFile,
                data: SchemaPrinter::doPrint(
                    schema: new SyncedQuerySchema(
                        entityManager: $this->entityManager
                    ),
                    options: [
                        'sortTypes' => false
                    ]
                )
            );
    
            foreach (
                [
                    DateTimeScalarTypeDefinition(),
                    LimitScalarTypeDefinition(),
                    PageScalarTypeDefinition()
                ] 
                as $scalarTypeDefinition
            ) {
                if (!$this->scalarTypeDefinitions->contains($scalarTypeDefinition)) {
                    $this->scalarTypeDefinitions->add($scalarTypeDefinition);
                }
            }
        }
    
        public function updateSchema(): void
        {
            // TODO: Update Schema
            
            if (\is_file($schemaCacheFile = $this->cacheDirectory.'/'.$this->schemaFileName)) {
                \unlink($schemaCacheFile);
            }
        }
    
        public function addScalarTypeDefinition(
            string $typeName
        ): void
        {
            $this->scalarTypeDefinitions
                ->add(
                    scalarTypeDefinition: GenericScalarTypeDefinition(
                        typeName: $typeName
                    )
                );
        }
    
        public function addConstraintPlugin(
            string $nodeType
        ): void
        {
            $this->plugins
                ->add(
                    plugin: ConstraintPlugin(
                        nodeType: $nodeType
                    )
                );
        }
    
        public function addRootConstraintPlugin(): void
        {
            $this->plugins
                ->add(
                    plugin: RootConstraintPlugin()
                );
        }
    
        public function addFilterPlugin(
            string $nodeType,
            string $filterName
        ): void
        {
            $this->plugins
                ->add(
                    plugin: FilterPlugin(
                        nodeType: $nodeType,
                        filterName: $filterName
                    )
                );
        }
    
        public function addOrderingPlugin(
            string $nodeType,
            string $orderingName
        ): void
        {
            $this->plugins
                ->add(
                    plugin: OrderingPlugin(
                        nodeType: $nodeType,
                        orderingName: $orderingName
                    )
                );
        }
    
        public function addSelectorPlugin(
            string $nodeType,
            string $fieldName
        ): void
        {
            $this->plugins
                ->add(
                    plugin: SelectorPlugin(
                        nodeType: $nodeType,
                        fieldName: $fieldName
                    )
                );
        }
    
        public function addResolverPlugin(
            string $nodeType,
            string $fieldName
        ): void
        {
            $this->plugins
                ->add(
                    plugin: ResolverPlugin(
                        nodeType: $nodeType,
                        fieldName: $fieldName
                    )
                );
        }
    
        public function addAuthorizorPlugin(
            string $nodeType,
            bool $isForCollections
        ): void
        {
            $this->plugins
                ->add(
                    plugin: AuthorizorPlugin(
                        nodeType: $nodeType,
                        isForCollections: $isForCollections
                    )
                );
        }
    
        public function addRootAuthorizorPlugin(): void
        {
            $this->plugins
                ->add(
                    plugin: RootAuthorizorPlugin()
                );
        }
    
        public function addMutationPlugin(
            string $fieldName
        ): void
        {
            $this->plugins
                ->add(
                    plugin: MutationPlugin(
                        fieldName: $fieldName
                    )
                );
        }
    
        public function addSubscriptionPlugin(
            string $fieldName
        ): void
        {
            $this->plugins
                ->add(
                    plugin: SubscriptionPlugin(
                        fieldName: $fieldName
                    )
                );
        }
    
        public function generateCache(): void
        {
            // Clear previous cache
            {
                if (\is_file($schemaCacheFile = $this->cacheDirectory.'/'.$this->schemaFileName)) {
                    \unlink($schemaCacheFile);
                }
    
                if (\is_file($schemaTypeDefinitionsCacheFile = $this->cacheDirectory.'/scalar_type_definitions.php')) {
                    \unlink($schemaTypeDefinitionsCacheFile);
                }
    
                if (\is_file($pluginsCacheFile = $this->cacheDirectory.'/plugins.php')) {
                    \unlink($pluginsCacheFile);
                }
            }
    
            // Generate Schema cache
            {
                if (!\is_file($schemaFile = $this->schemaFileDirectory.'/'.$this->schemaFileName)) {
                    throw new \Exception('No schema file! Kindly generate it first to proceed.');
                }
    
                $document = Parser::parse(
                    source: \is_string($schemaFileContents = \file_get_contents($schemaFile)) 
                                ? $schemaFileContents 
                                : throw new \Exception("Unable to read the schema file '$schemaFile'.")
                );
    
                file_force_put_contents($schemaCacheFile, "<?php\nreturn " . \var_export(AST::toArray($document), true) . ";\n");
            }
    
            // Generate Scalar Type Definitions cache
            {
                if (\count($scalarTypeDefinitions = \iterator_to_array($this->scalarTypeDefinitions)) > 0) {
                    $scalarTypeDefinitions = \var_export(
                        value: \array_map(
                            fn(ScalarTypeDefinition $scalarTypeDefinition) => $this->scalarTypeDefinitions->filePath($scalarTypeDefinition),
                            $scalarTypeDefinitions
                        ),
                        return: true
                    );
        
                    file_force_put_contents(
                        $schemaTypeDefinitionsCacheFile,
                        <<<EOD
                        <?php
        
                        return $scalarTypeDefinitions;
                        EOD
                    );
                }
            }
    
            // Generate Plugins cache
            {
                if (\count($plugins = \iterator_to_array($this->plugins)) > 0) {
                    $plugins = \var_export(
                        value: \array_map(
                            fn(PluginInfo $pluginInfo) => $this->plugins->filePath($pluginInfo),
                            $plugins
                        ),
                        return: true
                    );
        
                    file_force_put_contents(
                        $pluginsCacheFile,
                        <<<EOD
                        <?php
        
                        return $plugins;
                        EOD
                    );
                }
            }
        }
    };
}