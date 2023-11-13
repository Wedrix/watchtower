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
final class Console
{
    private readonly Plugins $plugins;

    private readonly ScalarTypeDefinitions $scalarTypeDefinitions;

    private readonly string $schemaCacheFile;

    private readonly string $schemaTypeDefinitionsCacheFile;

    private readonly string $pluginsCacheFile;

    /**
     * @param EntityManagerInterface $entityManager The Doctrine entityManager instance.
     * @param string $schemaFile The schema file.
     * @param string $pluginsDirectory The plugin functions' directory.
     * @param string $scalarTypeDefinitionsDirectory The scalar types' definitions' directory.
     * @param string $cacheDirectory The directory for storing cache files.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $schemaFile,
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

        $this->schemaCacheFile = $this->cacheDirectory.\DIRECTORY_SEPARATOR.'schema.php';

        $this->schemaTypeDefinitionsCacheFile = $this->cacheDirectory.\DIRECTORY_SEPARATOR.'scalar_type_definitions.php';

        $this->pluginsCacheFile = $this->cacheDirectory.\DIRECTORY_SEPARATOR.'plugins.php';
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
        if (\file_exists($this->schemaFile)) {
            throw new \Exception('A schema file already exists.');
        }

        \file_put_contents(
            filename: $this->schemaFile,
            data: SchemaPrinter::doPrint(
                schema: new SyncedQuerySchema(
                    entityManager: $this->entityManager
                ),
                options: [
                    'sortTypes' => false
                ]
            )
        );

        if (\file_exists($this->schemaCacheFile)) {
            \unlink($this->schemaCacheFile);
        }

        foreach (
            [
                new DateTimeScalarTypeDefinition(),
                new LimitScalarTypeDefinition(),
                new PageScalarTypeDefinition()
            ] 
            as $scalarTypeDefinition
        ) {
            if (!$this->scalarTypeDefinitions->contains($scalarTypeDefinition)) {
                $this->scalarTypeDefinitions
                    ->add($scalarTypeDefinition);
            }
        }
    }

    public function updateSchema(): void
    {
        // TODO: Update Schema
        
        if (\file_exists($this->schemaCacheFile)) {
            \unlink($this->schemaCacheFile);
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
        // Update Schema cache
        {
            if (\file_exists($this->schemaCacheFile)) {
                \unlink($this->schemaCacheFile);
            }

            $document = Parser::parse(
                source: \is_string($schemaFileContents = \file_get_contents($this->schemaFile)) 
                            ? $schemaFileContents 
                            : throw new \Exception("Unable to read the schema file '{$this->schemaFile}'.")
            );

            $dirname = \pathinfo($this->schemaCacheFile)['dirname'] ?? '';

            if (!\is_dir($dirname)) {
                \mkdir(directory: $dirname, recursive: true);
            }

            \file_put_contents($this->schemaCacheFile, "<?php\nreturn " . \var_export(AST::toArray($document), true) . ";\n");
        }

        // Update Scalar Type Definitions cache
        {
            if (\file_exists($this->schemaTypeDefinitionsCacheFile)) {
                \unlink($this->schemaTypeDefinitionsCacheFile);
            }

            $scalarTypeDefinitions = \var_export(
                value: \array_map(
                    fn(ScalarTypeDefinition $scalarTypeDefinition) => $this->scalarTypeDefinitions->filePath($scalarTypeDefinition),
                    \iterator_to_array($this->scalarTypeDefinitions)
                ),
                return: true
            );

            \file_put_contents(
                $this->schemaTypeDefinitionsCacheFile,
                <<<EOD
                <?php

                return $scalarTypeDefinitions;
                EOD
            );
        }

        // Update Plugins cache
        {
            if (\file_exists($this->pluginsCacheFile)) {
                \unlink($this->pluginsCacheFile);
            }

            $plugins = \var_export(
                value: \array_map(
                    fn(PluginInfo $pluginInfo) => $this->plugins->filePath($pluginInfo),
                    \iterator_to_array($this->plugins)
                ),
                return: true
            );

            \file_put_contents(
                $this->pluginsCacheFile,
                <<<EOD
                <?php

                return $plugins;
                EOD
            );
        }
    }
}