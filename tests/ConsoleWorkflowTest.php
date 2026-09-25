<?php

declare(strict_types=1);

namespace Watchtower\Tests;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Error\SyntaxError;
use PHPUnit\Framework\TestCase;
use Watchtower\Tests\Support\DoctrineEntityManagerFactory;
use Watchtower\Tests\Support\FixtureWorkspace;
use Wedrix\Watchtower\Console;
use Wedrix\Watchtower\ExistingSchemaConsoleException;

use function Wedrix\Watchtower\Console;
use function Wedrix\Watchtower\ConstraintPlugin;
use function Wedrix\Watchtower\FilterPlugin;
use function Wedrix\Watchtower\MutationPlugin;
use function Wedrix\Watchtower\NodeAuthorizorPlugin;
use function Wedrix\Watchtower\OrderingPlugin;
use function Wedrix\Watchtower\ProjectionPlugin;
use function Wedrix\Watchtower\ResolverPlugin;
use function Wedrix\Watchtower\ResultAuthorizorPlugin;
use function Wedrix\Watchtower\RootConstraintPlugin;
use function Wedrix\Watchtower\RootNodeAuthorizorPlugin;
use function Wedrix\Watchtower\RootResultAuthorizorPlugin;
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

    public function test_generate_schema_creates_schema_and_default_scalar_definitions(): void
    {
        $console = $this->createConsole();

        $console->generateSchema();

        self::assertFileExists($this->workspace->schemaFile());
        self::assertStringContainsString(
            'type Query',
            (string) \file_get_contents($this->workspace->schemaFile())
        );
        self::assertStringContainsString(
            '_cursor: Cursor',
            (string) \file_get_contents($this->workspace->schemaFile())
        );
        self::assertFileExists($this->workspace->scalarTypeDefinitionsDirectory().'/date_time_type_definition.php');
        self::assertFileExists($this->workspace->scalarTypeDefinitionsDirectory().'/limit_type_definition.php');
        self::assertFileExists($this->workspace->scalarTypeDefinitionsDirectory().'/page_type_definition.php');
        self::assertFileExists($this->workspace->scalarTypeDefinitionsDirectory().'/cursor_type_definition.php');
    }

    public function test_generate_schema_throws_when_schema_already_exists(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();

        $this->expectException(ExistingSchemaConsoleException::class);
        $this->expectExceptionMessage('already exists');

        $console->generateSchema();
    }

    public function test_plugin_generators_create_files_in_expected_directories(): void
    {
        $console = $this->createConsole();

        $console->addConstraintPlugin('Book');
        $console->addRootConstraintPlugin();
        $console->addFilterPlugin('Book', 'titleContains');
        $console->addOrderingPlugin('Book', 'titleAsc');
        $console->addSelectorPlugin('Book', 'titleLength');
        $console->addResolverPlugin('Book', 'externalScore');
        $console->addNodeAuthorizorPlugin('Book');
        $console->addRootNodeAuthorizorPlugin();
        $console->addProjectionPlugin('Book');
        $console->addResultAuthorizorPlugin('Book', true);
        $console->addRootResultAuthorizorPlugin();
        $console->addMutationPlugin('renameBook');

        $plugins = $console->plugins();

        self::assertFileExists($plugins->filePath(ConstraintPlugin('Book')));
        self::assertFileExists($plugins->filePath(RootConstraintPlugin()));
        self::assertFileExists($plugins->filePath(FilterPlugin('Book', 'titleContains')));
        self::assertFileExists($plugins->filePath(OrderingPlugin('Book', 'titleAsc')));
        self::assertFileExists($plugins->filePath(SelectorPlugin('Book', 'titleLength')));
        self::assertFileExists($plugins->filePath(ResolverPlugin('Book', 'externalScore')));
        self::assertFileExists($plugins->filePath(NodeAuthorizorPlugin('Book')));
        self::assertFileExists($plugins->filePath(RootNodeAuthorizorPlugin()));
        self::assertFileExists($plugins->filePath(ProjectionPlugin('Book')));
        self::assertFileExists($plugins->filePath(ResultAuthorizorPlugin('Book', true)));
        self::assertFileExists($plugins->filePath(RootResultAuthorizorPlugin()));
        self::assertFileExists($plugins->filePath(MutationPlugin('renameBook')));
    }

    public function test_generate_cache_creates_schema_plugins_and_scalar_type_definition_cache_files(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();
        $console->addFilterPlugin('Book', 'titleContains');

        $console->generateCache();

        self::assertFileExists($this->workspace->cacheDirectory().'/'.$this->workspace->schemaFileName());
        self::assertFileExists($this->workspace->cacheDirectory().'/plugins.php');
        self::assertFileExists($this->workspace->cacheDirectory().'/scalar_type_definitions.php');
    }

    public function test_nested_schema_name_uses_the_cache_filename_expected_by_schema(): void
    {
        $console = Console(
            entityManager: $this->entityManager,
            schemaFileDirectory: $this->workspace->schemaDirectory(),
            schemaFileName: 'nested/schema.graphql',
            pluginsDirectory: $this->workspace->pluginsDirectory(),
            scalarTypeDefinitionsDirectory: $this->workspace->scalarTypeDefinitionsDirectory(),
            cacheDirectory: $this->workspace->cacheDirectory()
        );
        $console->generateSchema();
        $console->generateCache();

        $schemaCacheFile = $this->workspace->cacheDirectory().'/schema.graphql';
        self::assertFileExists($schemaCacheFile);

        $console->updateSchema();
        self::assertFileDoesNotExist($schemaCacheFile);
    }

    public function test_failed_cache_generation_preserves_published_files(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();
        $console->addFilterPlugin('Book', 'titleContains');
        $console->generateCache();
        $published = [];
        foreach (\glob($this->workspace->cacheDirectory().'/*') as $file) {
            $published[$file] = \file_get_contents($file);
        }

        $this->workspace->writeSchema('type Query {');
        $this->expectException(SyntaxError::class);
        try {
            $console->generateCache();
        } finally {
            foreach ($published as $file => $contents) {
                self::assertFileExists($file);
                self::assertSame($contents, \file_get_contents($file));
            }
            self::assertSame([], \glob($this->workspace->cacheDirectory().'/.generate-*'));
        }
    }

    public function test_cache_generation_publishes_empty_lists_after_removing_plugins_and_scalars(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();
        $console->addFilterPlugin('Book', 'titleContains');
        $console->generateCache();

        \unlink($console->plugins()->filePath(FilterPlugin('Book', 'titleContains')));
        foreach ($console->scalarTypeDefinitions() as $definition) {
            \unlink($console->scalarTypeDefinitions()->filePath($definition));
        }
        $this->workspace->writeSchema('type Query { hello: String }');
        $console->generateCache();

        self::assertSame([], require $this->workspace->cacheDirectory().'/plugins.php');
        self::assertSame([], require $this->workspace->cacheDirectory().'/scalar_type_definitions.php');
    }

    public function test_concurrent_cache_generation_keeps_published_files_readable(): void
    {
        if (! \function_exists('proc_open')) {
            self::markTestSkipped('proc_open is required for concurrent cache generation.');
        }

        $console = $this->createConsole();
        $console->generateSchema();
        $console->addFilterPlugin('Book', 'titleContains');
        $console->generateCache();
        $published = [];
        foreach (\glob($this->workspace->cacheDirectory().'/*') as $file) {
            $published[$file] = \file_get_contents($file);
        }
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $console = \Wedrix\Watchtower\Console(
                entityManager: \Watchtower\Tests\Support\DoctrineEntityManagerFactory::create($argv[1].'/tests/Support/Fixtures/mappings'),
                schemaFileDirectory: $argv[2].'/schema',
                schemaFileName: 'schema.graphql',
                pluginsDirectory: $argv[2].'/plugins',
                scalarTypeDefinitionsDirectory: $argv[2].'/scalar_type_definitions',
                cacheDirectory: $argv[2].'/cache'
            );
            while (microtime(true) < (float) $argv[3]) {
                usleep(1000);
            }
            for ($iteration = 0; $iteration < 20; ++$iteration) {
                $console->generateCache();
            }
            PHP;
        $children = [];
        $start = \microtime(true) + 0.3;
        $reads = 0;
        try {
            for ($index = 0; $index < 4; $index++) {
                $process = \proc_open([
                    \PHP_BINARY, '-d', 'opcache.enable_cli=0', '-r', $code,
                    \dirname(__DIR__), $this->workspace->rootDirectory(), (string) $start,
                ], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
                self::assertIsResource($process);
                \stream_set_blocking($pipes[1], false);
                $children[] = [$process, $pipes[1], ''];
            }

            $deadline = \microtime(true) + 15;
            while ($children !== []) {
                foreach ($published as $file => $contents) {
                    self::assertSame($contents, \file_get_contents($file));
                }
                $reads++;
                foreach ($children as $index => [$process, $pipe, $output]) {
                    $children[$index][2] = $output.\stream_get_contents($pipe);
                    $status = \proc_get_status($process);
                    if (! $status['running']) {
                        $output = $children[$index][2].\stream_get_contents($pipe);
                        \fclose($pipe);
                        \proc_close($process);
                        unset($children[$index]);
                        self::assertSame(0, $status['exitcode'], $output);
                    }
                }
                self::assertLessThan($deadline, \microtime(true), 'Concurrent cache generation timed out.');
                \usleep(1000);
            }
            self::assertGreaterThan(0, $reads);
            self::assertSame([], \glob($this->workspace->cacheDirectory().'/.generate-*'));
        } finally {
            foreach ($children as [$process, $pipe]) {
                \proc_terminate($process);
                \fclose($pipe);
                \proc_close($process);
            }
        }
    }

    public function test_update_schema_invalidates_existing_schema_cache_file(): void
    {
        $console = $this->createConsole();
        $console->generateSchema();
        $console->generateCache();

        $schemaCacheFile = $this->workspace->cacheDirectory().'/'.$this->workspace->schemaFileName();
        self::assertFileExists($schemaCacheFile);

        $console->updateSchema();

        self::assertFileDoesNotExist($schemaCacheFile);
    }

    private function createConsole(): Console
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
