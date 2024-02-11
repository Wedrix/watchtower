<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Language\Parser;
use GraphQL\Utils\AST;
use GraphQL\Utils\SchemaPrinter;
use Wedrix\Watchtower\Plugin\AuthorizorPlugin;
use Wedrix\Watchtower\Plugin\FilterPlugin;
use Wedrix\Watchtower\Plugin\MutationPlugin;
use Wedrix\Watchtower\Plugin\OrderingPlugin;
use Wedrix\Watchtower\Plugin\ResolverPlugin;
use Wedrix\Watchtower\Plugin\SelectorPlugin;
use Wedrix\Watchtower\Plugin\SubscriptionPlugin;
use Wedrix\Watchtower\ScalarTypeDefinition\DateTimeScalarTypeDefinition;
use Wedrix\Watchtower\ScalarTypeDefinition\GenericScalarTypeDefinition;
use Wedrix\Watchtower\ScalarTypeDefinition\LimitScalarTypeDefinition;
use Wedrix\Watchtower\ScalarTypeDefinition\PageScalarTypeDefinition;

/**
 * @api
 */
class Console
{
    private readonly Plugins $plugins;

    private readonly ScalarTypeDefinitions $scalarTypeDefinitions;

    /**
     * @param EntityManagerInterface $entityManager The Doctrine entityManager instance.
     * @param string $schemaFileDirectory The directory of the schema file.
     * @param string $schemaFileName The name of the schema file.
     * @param string $pluginsDirectory The plugin functions' directory.
     * @param string $scalarTypeDefinitionsDirectory The scalar types' definitions' directory.
     * @param string $cacheDirectory The directory for storing cache files.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $schemaFileDirectory,
        private readonly string $schemaFileName,
        private readonly string $pluginsDirectory,
        private readonly string $scalarTypeDefinitionsDirectory,
        private readonly string $cacheDirectory
    )
    {
        $this->plugins = new Plugins(
            directory: $this->pluginsDirectory,
            cacheDirectory: $this->cacheDirectory,
            optimize: false
        );

        $this->scalarTypeDefinitions = new ScalarTypeDefinitions(
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
                new DateTimeScalarTypeDefinition(),
                new LimitScalarTypeDefinition(),
                new PageScalarTypeDefinition()
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
                scalarTypeDefinition: new GenericScalarTypeDefinition(
                    typeName: $typeName
                )
            );
    }

    public function addFilterPlugin(
        string $parentNodeType,
        string $filterName
    ): void
    {
        $this->plugins
            ->add(
                plugin: new FilterPlugin(
                    parentNodeType: $parentNodeType,
                    filterName: $filterName
                )
            );
    }

    public function addOrderingPlugin(
        string $parentNodeType,
        string $orderingName
    ): void
    {
        $this->plugins
            ->add(
                plugin: new OrderingPlugin(
                    parentNodeType: $parentNodeType,
                    orderingName: $orderingName
                )
            );
    }

    public function addSelectorPlugin(
        string $parentNodeType,
        string $fieldName
    ): void
    {
        $this->plugins
            ->add(
                plugin: new SelectorPlugin(
                    parentNodeType: $parentNodeType,
                    fieldName: $fieldName
                )
            );
    }

    public function addResolverPlugin(
        string $parentNodeType,
        string $fieldName
    ): void
    {
        $this->plugins
            ->add(
                plugin: new ResolverPlugin(
                    parentNodeType: $parentNodeType,
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
                plugin: new AuthorizorPlugin(
                    nodeType: $nodeType,
                    isForCollections: $isForCollections
                )
            );
    }

    public function addMutationPlugin(
        string $fieldName
    ): void
    {
        $this->plugins
            ->add(
                plugin: new MutationPlugin(
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
                plugin: new SubscriptionPlugin(
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
}