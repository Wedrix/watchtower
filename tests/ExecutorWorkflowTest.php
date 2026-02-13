<?php

declare(strict_types=1);

namespace Watchtower\Tests;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Error\DebugFlag;
use PHPUnit\Framework\TestCase;
use Watchtower\Tests\Support\DoctrineEntityManagerFactory;
use Watchtower\Tests\Support\Fixtures\Entity\Author;
use Watchtower\Tests\Support\Fixtures\Entity\Book;
use Watchtower\Tests\Support\FixtureWorkspace;

use function Wedrix\Watchtower\AuthorizorPlugin;
use function Wedrix\Watchtower\Console;
use function Wedrix\Watchtower\Executor;
use function Wedrix\Watchtower\FilterPlugin;
use function Wedrix\Watchtower\MutationPlugin;
use function Wedrix\Watchtower\OrderingPlugin;
use function Wedrix\Watchtower\ResolverPlugin;
use function Wedrix\Watchtower\RootAuthorizorPlugin;
use function Wedrix\Watchtower\SelectorPlugin;

/**
 * @group executor
 */
final class ExecutorWorkflowTest extends TestCase
{
    private FixtureWorkspace $workspace;

    private EntityManagerInterface $entityManager;

    private int $firstAuthorId;

    private int $firstBookId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new FixtureWorkspace(
            prefix: 'watchtower_executor_',
            fixedIdentifier: 'watchtower_executor_workspace'
        );
        $this->entityManager = DoctrineEntityManagerFactory::create(
            __DIR__.'/Support/Fixtures/mappings'
        );

        $seed = $this->workspace->seedLibraryData($this->entityManager);

        $this->firstAuthorId = $seed['authors'][0]->getId() ?? throw new \RuntimeException('Expected seeded author id.');
        $this->firstBookId = $seed['books'][0]->getId() ?? throw new \RuntimeException('Expected seeded book id.');

        $this->prepareExecutorWorkspace();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function test_query_workflow_covers_relations_filters_ordering_pagination_selector_and_resolver(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($needle: String!) {
              books(
                queryParams: {
                  filters: { titleContains: $needle }
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  limit: 2
                  page: 1
                  distinct: true
                }
              ) {
                id
                title
                titleLength
                externalScore
                author {
                  id
                  name
                }
              }
            }
            GRAPHQL,
            [
                'scoreMultiplier' => 2,
            ],
            [
                'needle' => 'graphql',
            ]
        );

        $this->assertNoErrors($result);

        $books = $result['data']['books'] ?? [];

        self::assertCount(2, $books);
        self::assertSame('GraphQL Basics', $books[0]['title']);
        self::assertSame('GraphQL in Action', $books[1]['title']);
        self::assertSame(\strlen('GraphQL Basics'), $books[0]['titleLength']);
        self::assertSame(\strlen('GraphQL in Action') * 2, $books[1]['externalScore']);
        self::assertSame('Ada Lovelace', $books[0]['author']['name']);
        self::assertSame('Alan Turing', $books[1]['author']['name']);
    }

    public function test_many_to_many_relation_resolution_from_books_to_tags(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(
                queryParams: {
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                }
              ) {
                title
                tags {
                  name
                }
              }
              tags {
                name
                books {
                  title
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $books = $result['data']['books'] ?? [];
        $tagsByBook = [];

        foreach ($books as $book) {
            $bookTitle = (string) ($book['title'] ?? '');
            $tagNames = \array_map(
                static fn (array $tag): string => (string) ($tag['name'] ?? ''),
                $book['tags'] ?? []
            );
            \sort($tagNames);
            $tagsByBook[$bookTitle] = $tagNames;
        }

        \ksort($tagsByBook);

        self::assertSame(
            [
                'GraphQL Basics' => ['graphql', 'php'],
                'GraphQL in Action' => ['graphql'],
                'PHP Patterns' => ['php'],
                'Zed Algorithms' => ['algorithms'],
            ],
            $tagsByBook
        );

        $tags = $result['data']['tags'] ?? [];
        $booksByTag = [];

        foreach ($tags as $tag) {
            $tagName = (string) ($tag['name'] ?? '');
            $bookTitles = \array_map(
                static fn (array $book): string => (string) ($book['title'] ?? ''),
                $tag['books'] ?? []
            );
            \sort($bookTitles);
            $booksByTag[$tagName] = $bookTitles;
        }

        \ksort($booksByTag);

        self::assertSame(
            [
                'algorithms' => ['Zed Algorithms'],
                'graphql' => ['GraphQL Basics', 'GraphQL in Action'],
                'php' => ['GraphQL Basics', 'PHP Patterns'],
            ],
            $booksByTag
        );
    }

    public function test_one_to_many_relation_resolution_from_authors_to_books(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              authors {
                name
                books(
                  queryParams: {
                    ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  }
                ) {
                  title
                  author {
                    name
                  }
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $authors = $result['data']['authors'] ?? [];
        $booksByAuthor = [];

        foreach ($authors as $author) {
            $authorName = (string) ($author['name'] ?? '');
            $bookTitles = [];

            foreach ($author['books'] ?? [] as $book) {
                $bookTitles[] = (string) ($book['title'] ?? '');
                self::assertSame($authorName, (string) ($book['author']['name'] ?? ''));
            }

            \sort($bookTitles);
            $booksByAuthor[$authorName] = $bookTitles;
        }

        \ksort($booksByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['GraphQL Basics', 'PHP Patterns'],
                'Alan Turing' => ['GraphQL in Action', 'Zed Algorithms'],
            ],
            $booksByAuthor
        );
    }

    public function test_one_to_one_relation_resolution_on_owning_and_inverse_sides(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              authors {
                name
                profile {
                  bio
                  author {
                    name
                  }
                }
              }
              authorProfiles {
                bio
                author {
                  name
                  profile {
                    bio
                  }
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $authors = $result['data']['authors'] ?? [];
        $profilesByAuthor = [];

        foreach ($authors as $author) {
            $authorName = (string) ($author['name'] ?? '');
            $profileBio = (string) ($author['profile']['bio'] ?? '');
            $profilesByAuthor[$authorName] = $profileBio;
            self::assertSame($authorName, (string) ($author['profile']['author']['name'] ?? ''));
        }

        \ksort($profilesByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => 'First computer programmer and analytical engine pioneer.',
                'Alan Turing' => 'Computer scientist and father of theoretical computing.',
            ],
            $profilesByAuthor
        );

        $authorProfiles = $result['data']['authorProfiles'] ?? [];
        $authorsByBio = [];

        foreach ($authorProfiles as $authorProfile) {
            $bio = (string) ($authorProfile['bio'] ?? '');
            $authorName = (string) ($authorProfile['author']['name'] ?? '');
            $authorsByBio[$bio] = $authorName;
            self::assertSame($bio, (string) ($authorProfile['author']['profile']['bio'] ?? ''));
        }

        \ksort($authorsByBio);

        self::assertSame(
            [
                'Computer scientist and father of theoretical computing.' => 'Alan Turing',
                'First computer programmer and analytical engine pioneer.' => 'Ada Lovelace',
            ],
            $authorsByBio
        );
    }

    public function test_enum_input_and_output_resolution(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($kind: ContentKind!) {
              echoContentKind(kind: $kind)
            }
            GRAPHQL,
            [],
            [
                'kind' => 'AUTHOR',
            ]
        );

        $this->assertNoErrors($result);
        self::assertSame('AUTHOR', $result['data']['echoContentKind'] ?? null);
    }

    public function test_interface_resolution_requires_and_uses_typename(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              searchAsInterface {
                __typename
                id
                label
                kind
                ... on SearchBook {
                  pageCount
                }
                ... on SearchAuthor {
                  nationality
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $items = $result['data']['searchAsInterface'] ?? [];
        self::assertCount(2, $items);

        self::assertSame(
            [
                '__typename' => 'SearchBook',
                'id' => 'b-1',
                'label' => 'GraphQL Basics',
                'kind' => 'BOOK',
                'pageCount' => 320,
            ],
            $items[0]
        );

        self::assertSame(
            [
                '__typename' => 'SearchAuthor',
                'id' => 'a-1',
                'label' => 'Ada Lovelace',
                'kind' => 'AUTHOR',
                'nationality' => 'British',
            ],
            $items[1]
        );
    }

    public function test_union_resolution_uses_typename_with_inline_fragments(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              searchAsUnion {
                __typename
                ... on SearchBook {
                  id
                  label
                  kind
                  pageCount
                }
                ... on SearchAuthor {
                  id
                  label
                  kind
                  nationality
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $items = $result['data']['searchAsUnion'] ?? [];
        self::assertCount(2, $items);

        self::assertSame(
            [
                '__typename' => 'SearchBook',
                'id' => 'b-2',
                'label' => 'GraphQL in Action',
                'kind' => 'BOOK',
                'pageCount' => 280,
            ],
            $items[0]
        );

        self::assertSame(
            [
                '__typename' => 'SearchAuthor',
                'id' => 'a-2',
                'label' => 'Alan Turing',
                'kind' => 'AUTHOR',
                'nationality' => 'British',
            ],
            $items[1]
        );
    }

    public function test_mutation_workflow_mutates_persisted_state_and_is_visible_to_follow_up_query(): void
    {
        $mutationResult = $this->executeQuery(
            <<<'GRAPHQL'
            mutation($id: ID!, $title: String!) {
              renameBook(id: $id, title: $title) {
                id
                title
              }
            }
            GRAPHQL,
            [],
            [
                'id' => $this->firstBookId,
                'title' => 'Renamed with Mutation',
            ]
        );

        $this->assertNoErrors($mutationResult);
        self::assertSame('Renamed with Mutation', $mutationResult['data']['renameBook']['title']);

        $queryResult = $this->executeQuery(
            <<<'GRAPHQL'
            query($id: ID!) {
              book(id: $id) {
                id
                title
              }
            }
            GRAPHQL,
            [],
            [
                'id' => $this->firstBookId,
            ]
        );

        $this->assertNoErrors($queryResult);
        self::assertSame('Renamed with Mutation', $queryResult['data']['book']['title']);
    }

    public function test_collection_authorizor_blocks_unauthorized_results(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books {
                id
              }
            }
            GRAPHQL,
            [
                'allowBooks' => false,
            ]
        );

        $this->assertErrorContains($result, 'Unauthorized books collection');
    }

    public function test_root_authorizor_blocks_all_results_when_enabled_in_context(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books {
                id
              }
            }
            GRAPHQL,
            [
                'blockAll' => true,
            ]
        );

        $this->assertErrorContains($result, 'Blocked by root authorizor');
    }

    public function test_missing_filter_plugin_returns_meaningful_error(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(queryParams: { filters: { unimplemented: "foo" } }) {
                id
              }
            }
            GRAPHQL
        );

        $this->assertErrorContains($result, "No filter plugin exists for 'unimplemented'");
    }

    public function test_missing_ordering_plugin_returns_meaningful_error(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(
                queryParams: {
                  ordering: { unimplemented: { rank: 1 } }
                }
              ) {
                id
              }
            }
            GRAPHQL
        );

        $this->assertErrorContains($result, "No ordering plugin exists for 'unimplemented'");
    }

    public function test_pagination_fails_when_page_is_provided_without_limit(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(queryParams: { page: 1 }) {
                id
              }
            }
            GRAPHQL
        );

        $this->assertErrorContains($result, 'limit parameter is required');
    }

    public function test_optimize_mode_executes_using_generated_cache(): void
    {
        $this->createConsole()->generateCache();

        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books {
                id
                title
              }
            }
            GRAPHQL,
            [],
            null,
            true
        );

        $this->assertNoErrors($result);
        self::assertCount(4, $result['data']['books'] ?? []);
    }

    public function test_optimize_mode_fails_when_cache_was_not_generated(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('cache');

        $this->createExecutor(true);
    }

    public function test_buffers_are_cleared_between_executor_calls(): void
    {
        $executor = $this->createExecutor(false);

        $query = <<<'GRAPHQL'
        query {
          books {
            id
            title
          }
        }
        GRAPHQL;

        $firstResult = $executor
            ->executeQuery(
                source: $query,
                rootValue: [],
                contextValue: $this->defaultContext(),
                variableValues: null,
                operationName: null,
                validationRules: null
            )
            ->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

        $this->assertNoErrors($firstResult);

        $countBefore = \count($firstResult['data']['books'] ?? []);

        $author = $this->entityManager->find(Author::class, $this->firstAuthorId);
        self::assertInstanceOf(Author::class, $author);

        $newBook = new Book(
            $author,
            'Fresh GraphQL Stories',
            13.40,
            new \DateTimeImmutable('2024-03-01T00:00:00+00:00')
        );
        $this->entityManager->persist($newBook);
        $this->entityManager->flush();

        $secondResult = $executor
            ->executeQuery(
                source: $query,
                rootValue: [],
                contextValue: $this->defaultContext(),
                variableValues: null,
                operationName: null,
                validationRules: null
            )
            ->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

        $this->assertNoErrors($secondResult);

        $countAfter = \count($secondResult['data']['books'] ?? []);

        self::assertSame($countBefore + 1, $countAfter);
    }

    private function prepareExecutorWorkspace(): void
    {
        $this->workspace->writeSchema(self::schemaSource());
        $this->workspace->writeDefaultScalarTypeDefinitions();

        $console = $this->createConsole();
        $plugins = $console->plugins();

        $console->addSelectorPlugin('Book', 'titleLength');
        $selectorPlugin = SelectorPlugin('Book', 'titleLength');
        $this->writePluginFile(
            $plugins->filePath($selectorPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\SelectorPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\QueryBuilder;

            function {$selectorPlugin->name()}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
            {
                \$entityAlias = \$queryBuilder->rootEntityAlias();

                \$queryBuilder->addSelect("LENGTH(\$entityAlias.title) AS titleLength");
            }
            PHP
        );

        $console->addResolverPlugin('Book', 'externalScore');
        $resolverPlugin = ResolverPlugin('Book', 'externalScore');
        $this->writePluginFile(
            $plugins->filePath($resolverPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\ResolverPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;

            function {$resolverPlugin->name()}(
                Node \$node
            ): mixed
            {
                \$multiplier = (int) (\$node->context()['scoreMultiplier'] ?? 1);
                \$title = (string) (\$node->root()['title'] ?? '');

                return \\strlen(\$title) * \$multiplier;
            }
            PHP
        );

        $console->addResolverPlugin('Query', 'echoContentKind');
        $enumResolverPlugin = ResolverPlugin('Query', 'echoContentKind');
        $this->writePluginFile(
            $plugins->filePath($enumResolverPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\ResolverPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;

            function {$enumResolverPlugin->name()}(
                Node \$node
            ): mixed
            {
                return (string) (\$node->args()['kind'] ?? '');
            }
            PHP
        );

        $console->addResolverPlugin('Query', 'searchAsInterface');
        $interfaceResolverPlugin = ResolverPlugin('Query', 'searchAsInterface');
        $this->writePluginFile(
            $plugins->filePath($interfaceResolverPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\ResolverPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;

            function {$interfaceResolverPlugin->name()}(
                Node \$node
            ): mixed
            {
                return [
                    [
                        '__typename' => 'SearchBook',
                        'id' => 'b-1',
                        'label' => 'GraphQL Basics',
                        'kind' => 'BOOK',
                        'pageCount' => 320,
                    ],
                    [
                        '__typename' => 'SearchAuthor',
                        'id' => 'a-1',
                        'label' => 'Ada Lovelace',
                        'kind' => 'AUTHOR',
                        'nationality' => 'British',
                    ],
                ];
            }
            PHP
        );

        $console->addResolverPlugin('Query', 'searchAsUnion');
        $unionResolverPlugin = ResolverPlugin('Query', 'searchAsUnion');
        $this->writePluginFile(
            $plugins->filePath($unionResolverPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\ResolverPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;

            function {$unionResolverPlugin->name()}(
                Node \$node
            ): mixed
            {
                return [
                    [
                        '__typename' => 'SearchBook',
                        'id' => 'b-2',
                        'label' => 'GraphQL in Action',
                        'kind' => 'BOOK',
                        'pageCount' => 280,
                    ],
                    [
                        '__typename' => 'SearchAuthor',
                        'id' => 'a-2',
                        'label' => 'Alan Turing',
                        'kind' => 'AUTHOR',
                        'nationality' => 'British',
                    ],
                ];
            }
            PHP
        );

        $console->addFilterPlugin('Book', 'titleContains');
        $filterPlugin = FilterPlugin('Book', 'titleContains');
        $this->writePluginFile(
            $plugins->filePath($filterPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\FilterPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\QueryBuilder;

            function {$filterPlugin->name()}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
            {
                \$needle = \$node->args()['queryParams']['filters']['titleContains'] ?? null;

                if (!\\is_string(\$needle) || \$needle === '') {
                    return;
                }

                \$entityAlias = \$queryBuilder->rootEntityAlias();
                \$needleAlias = \$queryBuilder->reconciledAlias('titleContains');

                \$queryBuilder
                    ->andWhere("LOWER(\$entityAlias.title) LIKE :\$needleAlias")
                    ->setParameter(\$needleAlias, '%' . \\strtolower(\$needle) . '%');
            }
            PHP
        );

        $console->addOrderingPlugin('Book', 'titleAsc');
        $orderingPlugin = OrderingPlugin('Book', 'titleAsc');
        $this->writePluginFile(
            $plugins->filePath($orderingPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\OrderingPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\QueryBuilder;

            function {$orderingPlugin->name()}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
            {
                \$direction = \\strtoupper((string) (\$node->args()['queryParams']['ordering']['titleAsc']['params']['direction'] ?? 'ASC'));
                \$direction = \\in_array(\$direction, ['ASC', 'DESC'], true) ? \$direction : 'ASC';

                \$entityAlias = \$queryBuilder->rootEntityAlias();
                \$orderAlias = \$queryBuilder->reconciledAlias('titleOrder');

                \$queryBuilder
                    ->addSelect("LOWER(\$entityAlias.title) AS HIDDEN \$orderAlias")
                    ->addOrderBy(\$orderAlias, \$direction);
            }
            PHP
        );

        $console->addMutationPlugin('renameBook');
        $mutationPlugin = MutationPlugin('renameBook');
        $this->writePluginFile(
            $plugins->filePath($mutationPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\MutationPlugin;

            use Doctrine\\ORM\\EntityManagerInterface;
            use Watchtower\\Tests\\Support\\Fixtures\\Entity\\Book;
            use Wedrix\\Watchtower\\Resolver\\Node;

            function {$mutationPlugin->name()}(
                Node \$node
            ): mixed
            {
                \$entityManager = \$node->context()['entityManager'] ?? null;

                if (!\$entityManager instanceof EntityManagerInterface) {
                    throw new \\RuntimeException('Expected entity manager in context.');
                }

                \$book = \$entityManager->find(Book::class, (int) \$node->args()['id']);

                if (!\$book instanceof Book) {
                    throw new \\RuntimeException('Book not found.');
                }

                \$book->setTitle((string) \$node->args()['title']);
                \$entityManager->flush();

                return [
                    'id' => \$book->getId(),
                    'title' => \$book->getTitle(),
                    'price' => \$book->getPrice(),
                    'publishedAt' => \$book->getPublishedAt(),
                    'author' => [
                        'id' => \$book->getAuthor()->getId(),
                        'name' => \$book->getAuthor()->getName(),
                    ],
                ];
            }
            PHP
        );

        $console->addAuthorizorPlugin('Book', true);
        $collectionAuthorizor = AuthorizorPlugin('Book', true);
        $this->writePluginFile(
            $plugins->filePath($collectionAuthorizor),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\AuthorizorPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\Result;

            function {$collectionAuthorizor->name()}(
                Result \$result,
                Node \$node
            ): void
            {
                if ((\$node->context()['allowBooks'] ?? false) !== true) {
                    throw new \\RuntimeException('Unauthorized books collection.');
                }
            }
            PHP
        );

        $console->addRootAuthorizorPlugin();
        $rootAuthorizor = RootAuthorizorPlugin();
        $this->writePluginFile(
            $plugins->filePath($rootAuthorizor),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\AuthorizorPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\Result;

            function {$rootAuthorizor->name()}(
                Result \$result,
                Node \$node
            ): void
            {
                if ((\$node->context()['blockAll'] ?? false) === true) {
                    throw new \\RuntimeException('Blocked by root authorizor.');
                }
            }
            PHP
        );
    }

    private function executeQuery(
        string $source,
        array $context = [],
        ?array $variableValues = null,
        bool $optimize = false
    ): array {
        $result = $this->createExecutor($optimize)
            ->executeQuery(
                source: $source,
                rootValue: [],
                contextValue: \array_merge($this->defaultContext(), $context),
                variableValues: $variableValues,
                operationName: null,
                validationRules: null
            );

        return $result->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);
    }

    private function defaultContext(): array
    {
        return [
            'entityManager' => $this->entityManager,
            'scoreMultiplier' => 2,
            'allowBooks' => true,
            'blockAll' => false,
        ];
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

    private function createExecutor(
        bool $optimize
    ): \Wedrix\Watchtower\Executor {
        return Executor(
            entityManager: $this->entityManager,
            schemaFile: $this->workspace->schemaFile(),
            pluginsDirectory: $this->workspace->pluginsDirectory(),
            scalarTypeDefinitionsDirectory: $this->workspace->scalarTypeDefinitionsDirectory(),
            cacheDirectory: $this->workspace->cacheDirectory(),
            optimize: $optimize
        );
    }

    private function writePluginFile(
        string $filePath,
        string $source
    ): void {
        if (\file_put_contents($filePath, $source) === false) {
            throw new \RuntimeException("Unable to write plugin fixture '{$filePath}'.");
        }
    }

    private function assertNoErrors(
        array $result
    ): void {
        if (\array_key_exists('errors', $result)) {
            self::assertSame(
                [],
                $result['errors'],
                'Expected no GraphQL errors, got: '.\json_encode($result['errors'])
            );
        }
    }

    private function assertErrorContains(
        array $result,
        string $expectedFragment
    ): void {
        $errors = $result['errors'] ?? [];

        self::assertNotEmpty($errors, 'Expected GraphQL errors but result had none.');

        foreach ($errors as $error) {
            $message = (string) ($error['message'] ?? '');
            $debugMessage = (string) ($error['debugMessage'] ?? '');
            $extensionDebugMessage = (string) (($error['extensions']['debugMessage'] ?? ''));

            if (\stripos($message.' '.$debugMessage.' '.$extensionDebugMessage, $expectedFragment) !== false) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail(
            "Expected error containing '{$expectedFragment}', got: ".\json_encode($errors)
        );
    }

    private static function schemaSource(): string
    {
        return <<<'GRAPHQL'
        scalar DateTime
        scalar Limit
        scalar Page

        enum ContentKind {
          BOOK
          AUTHOR
        }

        interface SearchResult {
          id: ID!
          label: String!
          kind: ContentKind!
        }

        type SearchBook implements SearchResult {
          id: ID!
          label: String!
          kind: ContentKind!
          pageCount: Int!
        }

        type SearchAuthor implements SearchResult {
          id: ID!
          label: String!
          kind: ContentKind!
          nationality: String!
        }

        union SearchItem = SearchBook | SearchAuthor

        type Query {
          book(id: ID!): Book!
          books(queryParams: BooksQueryParams): [Book!]!
          authors(queryParams: AuthorsQueryParams): [Author!]!
          authorProfiles(queryParams: AuthorProfilesQueryParams): [AuthorProfile!]!
          tags(queryParams: TagsQueryParams): [Tag!]!
          echoContentKind(kind: ContentKind!): ContentKind!
          searchAsInterface: [SearchResult!]!
          searchAsUnion: [SearchItem!]!
        }

        type Mutation {
          renameBook(id: ID!, title: String!): Book!
        }

        type Author {
          id: ID!
          name: String!
          profile: AuthorProfile
          books(queryParams: BooksQueryParams): [Book!]!
        }

        type AuthorProfile {
          id: ID!
          bio: String!
          author: Author!
        }

        type Book {
          id: ID!
          title: String!
          price: Float!
          publishedAt: DateTime!
          author: Author!
          tags(queryParams: TagsQueryParams): [Tag!]!
          titleLength: Int!
          externalScore: Int!
        }

        type Tag {
          id: ID!
          name: String!
          books(queryParams: BooksQueryParams): [Book!]!
        }

        input AuthorsQueryParams {
          limit: Limit
          page: Page
          distinct: Boolean
        }

        input BooksQueryParams {
          filters: BooksQueryFilters
          ordering: BooksQueryOrdering
          limit: Limit
          page: Page
          distinct: Boolean
        }

        input AuthorProfilesQueryParams {
          limit: Limit
          page: Page
          distinct: Boolean
        }

        input TagsQueryParams {
          limit: Limit
          page: Page
          distinct: Boolean
        }

        input BooksQueryFilters {
          titleContains: String
          unimplemented: String
        }

        input BooksQueryOrdering {
          titleAsc: BooksOrderingRule
          unimplemented: BooksOrderingRule
        }

        input BooksOrderingRule {
          rank: Int!
          params: BooksOrderingParams
        }

        input BooksOrderingParams {
          direction: String
        }
        GRAPHQL;
    }
}
