<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Language\Parser;
use GraphQL\Utils\AST;
use GraphQL\Utils\SchemaPrinter;
use GraphQL\Validator\DocumentValidator;

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

    public function addSearchResolverPlugin(
        string $nodeType
    ): void;

    public function addNodeAuthorizorPlugin(
        string $nodeType
    ): void;

    public function addRootNodeAuthorizorPlugin(): void;

    public function addProjectionPlugin(
        string $nodeType
    ): void;

    public function addResultAuthorizorPlugin(
        string $nodeType,
        bool $isForCollections
    ): void;

    public function addRootResultAuthorizorPlugin(): void;

    public function addMutationPlugin(
        string $fieldName
    ): void;

    public function addSubscriptionPlugin(
        string $fieldName
    ): void;

    public function generateCache(): void;
}

/**
 * @param  EntityManagerInterface  $entityManager  The Doctrine entityManager instance.
 * @param  string  $schemaFileDirectory  The directory of the schema file.
 * @param  string  $schemaFileName  The name of the schema file.
 * @param  string  $pluginsDirectory  The plugin functions' directory.
 * @param  string  $scalarTypeDefinitionsDirectory  The scalar types' definitions' directory.
 * @param  string  $cacheDirectory  The directory for storing cache files.
 */
function Console(
    EntityManagerInterface $entityManager,
    string $schemaFileDirectory,
    string $schemaFileName,
    string $pluginsDirectory,
    string $scalarTypeDefinitionsDirectory,
    string $cacheDirectory
): Console {
    /**
     * @var \WeakMap<EntityManagerInterface,array<string,mixed>>|null
     */
    static $instances = null;

    if ($instances === null) {
        $instances = new \WeakMap;
    }

    if (! isset($instances[$entityManager])) {
        $instances[$entityManager] = [];
    }

    return $instances[$entityManager][$schemaFileDirectory][$schemaFileName][$pluginsDirectory][$scalarTypeDefinitionsDirectory][$cacheDirectory] ??= new class(entityManager: $entityManager, schemaFileDirectory: $schemaFileDirectory, schemaFileName: $schemaFileName, pluginsDirectory: $pluginsDirectory, scalarTypeDefinitionsDirectory: $scalarTypeDefinitionsDirectory, cacheDirectory: $cacheDirectory) implements Console
    {
        private Plugins $plugins;

        private ScalarTypeDefinitions $scalarTypeDefinitions;

        public function __construct(
            private EntityManagerInterface $entityManager,
            private string $schemaFileDirectory,
            private string $schemaFileName,
            private string $pluginsDirectory,
            private string $scalarTypeDefinitionsDirectory,
            private string $cacheDirectory
        ) {
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
                throw new ExistingSchemaConsoleException("The schema '$schemaFile' already exists. Kindly either update it using the console command or delete the file to proceed regenerating it.");
            }

            file_force_put_contents(
                filename: $schemaFile,
                data: SchemaPrinter::doPrint(
                    schema: new SyncedQuerySchema(
                        entityManager: $this->entityManager
                    ),
                    options: [
                        'sortTypes' => false,
                    ]
                )
            );

            foreach (
                [
                    DateTimeScalarTypeDefinition(),
                    LimitScalarTypeDefinition(),
                    PageScalarTypeDefinition(),
                    CursorScalarTypeDefinition(),
                ] as $scalarTypeDefinition
            ) {
                if (! $this->scalarTypeDefinitions->contains($scalarTypeDefinition)) {
                    $this->scalarTypeDefinitions->add($scalarTypeDefinition);
                }
            }
        }

        public function updateSchema(): void
        {
            // TODO: Update Schema

            if (\is_file($schemaCacheFile = $this->cacheDirectory.'/'.\pathinfo($this->schemaFileName, \PATHINFO_BASENAME))) {
                \unlink($schemaCacheFile);
            }
        }

        public function addScalarTypeDefinition(
            string $typeName
        ): void {
            $this->scalarTypeDefinitions
                ->add(
                    scalarTypeDefinition: GenericScalarTypeDefinition(
                        typeName: $typeName
                    )
                );
        }

        public function addConstraintPlugin(
            string $nodeType
        ): void {
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
        ): void {
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
        ): void {
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
        ): void {
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
        ): void {
            $this->plugins
                ->add(
                    plugin: ResolverPlugin(
                        nodeType: $nodeType,
                        fieldName: $fieldName
                    )
                );
        }

        public function addSearchResolverPlugin(
            string $nodeType
        ): void {
            $this->plugins
                ->add(
                    plugin: SearchResolverPlugin(
                        nodeType: $nodeType
                    )
                );
        }

        public function addNodeAuthorizorPlugin(
            string $nodeType
        ): void {
            $this->plugins
                ->add(
                    plugin: NodeAuthorizorPlugin(
                        nodeType: $nodeType
                    )
                );
        }

        public function addRootNodeAuthorizorPlugin(): void
        {
            $this->plugins
                ->add(
                    plugin: RootNodeAuthorizorPlugin()
                );
        }

        public function addProjectionPlugin(
            string $nodeType
        ): void {
            $this->plugins
                ->add(
                    plugin: ProjectionPlugin(
                        nodeType: $nodeType
                    )
                );
        }

        public function addResultAuthorizorPlugin(
            string $nodeType,
            bool $isForCollections
        ): void {
            $this->plugins
                ->add(
                    plugin: ResultAuthorizorPlugin(
                        nodeType: $nodeType,
                        isForCollections: $isForCollections
                    )
                );
        }

        public function addRootResultAuthorizorPlugin(): void
        {
            $this->plugins
                ->add(
                    plugin: RootResultAuthorizorPlugin()
                );
        }

        public function addMutationPlugin(
            string $fieldName
        ): void {
            $this->plugins
                ->add(
                    plugin: MutationPlugin(
                        fieldName: $fieldName
                    )
                );
        }

        public function addSubscriptionPlugin(
            string $fieldName
        ): void {
            $this->plugins
                ->add(
                    plugin: SubscriptionPlugin(
                        fieldName: $fieldName
                    )
                );
        }

        public function generateCache(): void
        {
            if (! \is_file($schemaFile = $this->schemaFileDirectory.'/'.$this->schemaFileName)) {
                throw new MissingSchemaConsoleException('No schema file! Kindly generate it first to proceed.');
            }

            $document = Parser::parse(
                source: \is_string($schemaFileContents = \file_get_contents($schemaFile))
                            ? $schemaFileContents
                            : throw new UnreadableSchemaFileConsoleException("Unable to read the schema file '$schemaFile'.")
            );
            DocumentValidator::assertValidSDL($document);

            $caches = [
                \pathinfo($this->schemaFileName, \PATHINFO_BASENAME) => AST::toArray($document),
                'scalar_type_definitions.php' => \array_map(
                    fn (ScalarTypeDefinition $definition) => $this->scalarTypeDefinitions->filePath($definition),
                    \iterator_to_array($this->scalarTypeDefinitions)
                ),
                'plugins.php' => \array_map(
                    fn (PluginInfo $plugin) => $this->plugins->filePath($plugin),
                    \iterator_to_array($this->plugins)
                ),
            ];

            if (! \is_dir($this->cacheDirectory)
                && ! @\mkdir($this->cacheDirectory, 0777, true)
                && ! \is_dir($this->cacheDirectory)) {
                throw new \RuntimeException("Unable to create cache directory '{$this->cacheDirectory}'.");
            }

            $generationDirectory = $this->cacheDirectory.'/.generate-'.\bin2hex(\random_bytes(12));
            if (! \mkdir($generationDirectory, 0777)) {
                throw new \RuntimeException("Unable to create cache generation directory '$generationDirectory'.");
            }

            try {
                foreach ($caches as $filename => $data) {
                    $contents = "<?php\nreturn ".\var_export($data, true).";\n";
                    if (\file_put_contents($generationDirectory.'/'.$filename, $contents) !== \strlen($contents)) {
                        throw new \RuntimeException("Unable to write cache file '$filename'.");
                    }
                }

                foreach ($caches as $filename => $data) {
                    if (! \rename($generationDirectory.'/'.$filename, $this->cacheDirectory.'/'.$filename)) {
                        throw new \RuntimeException("Unable to publish cache file '$filename'.");
                    }
                }
            } finally {
                foreach ($caches as $filename => $data) {
                    if (\is_file($file = $generationDirectory.'/'.$filename)) {
                        \unlink($file);
                    }
                }
                \rmdir($generationDirectory);
            }
        }
    };
}
