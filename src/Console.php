<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
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
                scalarTypeDefinition: new GenericScalarTypeDefinition(
                    typeName: $typeName
                )
            );
    }

    public function scalarTypeDefinitions(): ScalarTypeDefinitions
    {
        return $this->scalarTypeDefinitions;
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

    public function plugins(): Plugins
    {
        return $this->plugins;
    }
}