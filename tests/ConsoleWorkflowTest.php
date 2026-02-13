<?php

declare(strict_types=1);

namespace Watchtower\Tests;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Watchtower\Tests\Support\DoctrineEntityManagerFactory;
use Watchtower\Tests\Support\FixtureWorkspace;

use function Wedrix\Watchtower\AuthorizorPlugin;
use function Wedrix\Watchtower\ConstraintPlugin;
use function Wedrix\Watchtower\Console;
use function Wedrix\Watchtower\FilterPlugin;
use function Wedrix\Watchtower\MutationPlugin;
use function Wedrix\Watchtower\OrderingPlugin;
use function Wedrix\Watchtower\ResolverPlugin;
use function Wedrix\Watchtower\RootAuthorizorPlugin;
use function Wedrix\Watchtower\RootConstraintPlugin;
use function Wedrix\Watchtower\SelectorPlugin;

/**
 * @group console
 */
final class ConsoleWorkflowTest extends TestCase
{
    private FixtureWorkspace $workspace;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new FixtureWorkspace('watchtower_console_');
        $this->entityManager = DoctrineEntityManagerFactory::create(
            __DIR__.'/Support/Fixtures/mappings'
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function testGenerateSchemaCreatesSchemaAndDefaultScalarDefinitions(): void
    {
        $console = $this->createConsole();

        $console->generateSchema();

        self::assertFileExists($this->workspace->schemaFile());
        self::assertStringContainsString(
            'type Query',
            (string) \file_get_contents($this->workspace->schemaFile())
        );
        self::assertFileExists($this->workspace->scalarTypeDefinitionsDirectory().'/date_time_type_definition.php');
        self::assertFileExists($this->workspace->scalarTypeDefinitionsDirectory().'/limit_type_definition.php');
        self::assertFileExists($this->workspace->scalarTypeDefinitionsDirectory().'/page_type_definition.php');
    }

    public function testGenerateSchemaThrowsWhenSchemaAlreadyExists(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already exists');

        $console->generateSchema();
    }

    public function testPluginGeneratorsCreateFilesInExpectedDirectories(): void
    {
        $console = $this->createConsole();

        $console->addConstraintPlugin('Book');
        $console->addRootConstraintPlugin();
        $console->addFilterPlugin('Book', 'titleContains');
        $console->addOrderingPlugin('Book', 'titleAsc');
        $console->addSelectorPlugin('Book', 'titleLength');
        $console->addResolverPlugin('Book', 'externalScore');
        $console->addAuthorizorPlugin('Book', true);
        $console->addRootAuthorizorPlugin();
        $console->addMutationPlugin('renameBook');

        $plugins = $console->plugins();

        self::assertFileExists($plugins->filePath(ConstraintPlugin('Book')));
        self::assertFileExists($plugins->filePath(RootConstraintPlugin()));
        self::assertFileExists($plugins->filePath(FilterPlugin('Book', 'titleContains')));
        self::assertFileExists($plugins->filePath(OrderingPlugin('Book', 'titleAsc')));
        self::assertFileExists($plugins->filePath(SelectorPlugin('Book', 'titleLength')));
        self::assertFileExists($plugins->filePath(ResolverPlugin('Book', 'externalScore')));
        self::assertFileExists($plugins->filePath(AuthorizorPlugin('Book', true)));
        self::assertFileExists($plugins->filePath(RootAuthorizorPlugin()));
        self::assertFileExists($plugins->filePath(MutationPlugin('renameBook')));
    }

    public function testGenerateCacheCreatesSchemaPluginsAndScalarTypeDefinitionCacheFiles(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();
        $console->addFilterPlugin('Book', 'titleContains');

        $console->generateCache();

        self::assertFileExists($this->workspace->cacheDirectory().'/'.$this->workspace->schemaFileName());
        self::assertFileExists($this->workspace->cacheDirectory().'/plugins.php');
        self::assertFileExists($this->workspace->cacheDirectory().'/scalar_type_definitions.php');
    }

    public function testUpdateSchemaInvalidatesExistingSchemaCacheFile(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();
        $console->generateCache();

        $schemaCacheFile = $this->workspace->cacheDirectory().'/'.$this->workspace->schemaFileName();
        self::assertFileExists($schemaCacheFile);

        $console->updateSchema();

        self::assertFileDoesNotExist($schemaCacheFile);
    }

    private function createConsole(): \Wedrix\Watchtower\Console
    {
        return Console(
            entityManager: $this->entityManager,
            schemaFileDirectory: $this->workspace->schemaDirectory(),
            schemaFileName: $this->workspace->schemaFileName(),
            pluginsDirectory: $this->workspace->pluginsDirectory(),
            scalarTypeDefinitionsDirectory: $this->workspace->scalarTypeDefinitionsDirectory(),
            cacheDirectory: $this->workspace->cacheDirectory()
        );
    }
}
