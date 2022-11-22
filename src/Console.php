<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Utils\SchemaPrinter;
use Wedrix\Watchtower\Plugins\AuthorizorPlugin;
use Wedrix\Watchtower\Plugins\FilterPlugin;
use Wedrix\Watchtower\Plugins\MutationPlugin;
use Wedrix\Watchtower\Plugins\OrderingPlugin;
use Wedrix\Watchtower\Plugins\ResolverPlugin;
use Wedrix\Watchtower\Plugins\SelectorPlugin;
use Wedrix\Watchtower\Plugins\SubscriptionPlugin;

/**
 * @api
 */
final class Console
{
    private readonly Plugins $plugins;

    private readonly ScalarTypeDefinitions $scalarTypeDefinitions;

    /**
     * @param EntityManagerInterface $entityManager The Doctrine entityManager instance.
     * @param string $schemaFileDirectory The schema file's directory.
     * @param string $schemaCacheFileDirectory The schema's generated cache file's directory.
     * @param string $pluginsDirectory The plugin functions' directory.
     * @param string $scalarTypeDefinitionsDirectory The scalar types' definitions' directory.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $schemaFileDirectory,
        private readonly string $schemaCacheFileDirectory,
        private readonly string $pluginsDirectory,
        private readonly string $scalarTypeDefinitionsDirectory,
    )
    {
        $this->plugins = (function (): Plugins {
            return new Plugins(
                directory: $this->pluginsDirectory
            );
        })();

        $this->scalarTypeDefinitions = (function (): ScalarTypeDefinitions {
            return new ScalarTypeDefinitions(
                directory: $this->scalarTypeDefinitionsDirectory
            );
        })();
    }

    public function generateSchema(): void
    {
        if (file_exists($this->schemaFileDirectory)) {
            throw new \Exception("A schema file already exists.");
        }

        file_put_contents(
            filename: $this->schemaFileDirectory,
            data: SchemaPrinter::doPrint(
                schema: new SyncedQuerySchema(
                    entityManager: $this->entityManager
                ),
                options: [
                    'sortTypes' => false
                ]
            )
        );

        if (file_exists($this->schemaCacheFileDirectory)) {
            unlink($this->schemaCacheFileDirectory);
        }
    }

    /**
     * Update queries in the schema file to match the project\'s current Doctrine models.
     * Busts the cache to reflect the changes.
     */
    public function updateSchema(): void
    {
        if (file_exists($this->schemaCacheFileDirectory)) {
            unlink($this->schemaCacheFileDirectory);
        }
    }

    public function addScalarTypeDifinition(
        string $typeName
    ): void
    {
        $this->scalarTypeDefinitions
            ->add(
                scalarTypeDefinition: new ScalarTypeDefinition(
                    typeName: $typeName
                )
            );
    }

    public function scalarTypeDefinitions(): ScalarTypeDefinitions
    {
        return $this->scalarTypeDefinitions;
    }

    public function addFilterPlugin(
        string $nodeType,
        string $filter
    ): void
    {
        $this->plugins
            ->add(
                plugin: new FilterPlugin(
                    nodeType: $nodeType,
                    filter: $filter
                )
            );
    }

    public function addOrderingPlugin(
        string $nodeType,
        string $ordering
    ): void
    {
        $this->plugins
            ->add(
                plugin: new OrderingPlugin(
                    nodeType: $nodeType,
                    ordering: $ordering
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
                plugin: new SelectorPlugin(
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
                plugin: new ResolverPlugin(
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

    public function plugins(): Plugins
    {
        return $this->plugins;
    }
}