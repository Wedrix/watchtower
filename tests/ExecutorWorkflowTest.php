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
use Wedrix\Watchtower\Console;
use Wedrix\Watchtower\Executor;
use Wedrix\Watchtower\MissingSchemaCacheSchemaException;

use function Wedrix\Watchtower\AuthorizorPlugin;
use function Wedrix\Watchtower\Console;
use function Wedrix\Watchtower\Executor;
use function Wedrix\Watchtower\FilterPlugin;
use function Wedrix\Watchtower\MutationPlugin;
use function Wedrix\Watchtower\OrderingPlugin;
use function Wedrix\Watchtower\ResolverPlugin;
use function Wedrix\Watchtower\RootAuthorizorPlugin;
use function Wedrix\Watchtower\SearchResolverPlugin;
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

    public function test_schema_declared_recommendation_fields_resolve_across_authors_and_books(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              authors {
                name
                recommendedBooks(
                  queryParams: {
                    ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  }
                ) {
                  title
                  recommendingAuthors {
                    name
                  }
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $recommendedBooksByAuthor = [];
        $recommendingAuthorsByBookByAuthor = [];

        foreach (($result['data']['authors'] ?? []) as $author) {
            $authorName = (string) ($author['name'] ?? '');
            $recommendedBooksByAuthor[$authorName] = [];
            $recommendingAuthorsByBookByAuthor[$authorName] = [];

            foreach (($author['recommendedBooks'] ?? []) as $recommendedBook) {
                $bookTitle = (string) ($recommendedBook['title'] ?? '');
                $recommendedBooksByAuthor[$authorName][] = $bookTitle;

                $recommendingAuthors = \array_map(
                    static fn (array $recommendingAuthor): string => (string) ($recommendingAuthor['name'] ?? ''),
                    $recommendedBook['recommendingAuthors'] ?? []
                );
                \sort($recommendingAuthors);

                $recommendingAuthorsByBookByAuthor[$authorName][$bookTitle] = $recommendingAuthors;
            }
        }

        \ksort($recommendedBooksByAuthor);
        \ksort($recommendingAuthorsByBookByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['GraphQL Basics', 'PHP Patterns'],
                'Alan Turing' => ['GraphQL Basics', 'Zed Algorithms'],
            ],
            $recommendedBooksByAuthor
        );
        self::assertSame(['Ada Lovelace', 'Alan Turing'], $recommendingAuthorsByBookByAuthor['Ada Lovelace']['GraphQL Basics']);
        self::assertSame(['Ada Lovelace'], $recommendingAuthorsByBookByAuthor['Ada Lovelace']['PHP Patterns']);
        self::assertSame(['Alan Turing'], $recommendingAuthorsByBookByAuthor['Alan Turing']['Zed Algorithms']);
    }

    public function test_schema_declared_recommendation_fields_support_nested_collection_query_params(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              authors {
                name
                recommendedBooks(
                  queryParams: {
                    ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                    limit: 1
                    page: 1
                  }
                ) {
                  title
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $recommendedBooksByAuthor = [];

        foreach (($result['data']['authors'] ?? []) as $author) {
            $recommendedBooksByAuthor[(string) $author['name']] = \array_map(
                static fn (array $recommendedBook): string => (string) $recommendedBook['title'],
                $author['recommendedBooks'] ?? []
            );
        }

        \ksort($recommendedBooksByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['GraphQL Basics'],
                'Alan Turing' => ['GraphQL Basics'],
            ],
            $recommendedBooksByAuthor
        );
    }

    public function test_misconfigured_schema_declared_recommendation_fields_return_meaningful_errors(): void
    {
        $ambiguousResult = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books {
                title
                ambiguousRecommendingAuthors {
                  name
                }
              }
            }
            GRAPHQL
        );

        $this->assertErrorContains($ambiguousResult, "must define exactly one association to parent entity 'Book'");

        $missingAssociationResult = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books {
                title
                missingRecommendingAuthors {
                  name
                }
              }
            }
            GRAPHQL
        );

        $this->assertErrorContains($missingAssociationResult, "does not define an association named 'missingAssociation'");
    }

    public function test_search_resolver_workflow_returns_matching_books_with_nested_relations(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(queryParams: { search: "graphql" }) {
                title
                price
                author {
                  name
                }
                tags {
                  name
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        self::assertSame(
            [
                [
                    'title' => 'GraphQL Basics',
                    'price' => 12.5,
                    'author' => [
                        'name' => 'Ada Lovelace',
                    ],
                    'tags' => [
                        [
                            'name' => 'graphql',
                        ],
                        [
                            'name' => 'php',
                        ],
                    ],
                ],
                [
                    'title' => 'GraphQL in Action',
                    'price' => 15.75,
                    'author' => [
                        'name' => 'Alan Turing',
                    ],
                    'tags' => [
                        [
                            'name' => 'graphql',
                        ],
                    ],
                ],
            ],
            $result['data']['books'] ?? []
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

    public function test_nested_collection_pagination_is_applied_per_parent(): void
    {
        $firstPageResult = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              authors {
                name
                books(
                  queryParams: {
                    ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                    limit: 1
                    page: 1
                  }
                ) {
                  title
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($firstPageResult);

        $booksByAuthor = [];

        foreach (($firstPageResult['data']['authors'] ?? []) as $author) {
            $booksByAuthor[(string) $author['name']] = \array_map(
                static fn (array $book): string => (string) $book['title'],
                $author['books'] ?? []
            );
        }

        \ksort($booksByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['GraphQL Basics'],
                'Alan Turing' => ['GraphQL in Action'],
            ],
            $booksByAuthor
        );

        $secondPageResult = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              authors {
                name
                books(
                  queryParams: {
                    ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                    limit: 1
                    page: 2
                  }
                ) {
                  title
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($secondPageResult);

        $booksByAuthor = [];

        foreach (($secondPageResult['data']['authors'] ?? []) as $author) {
            $booksByAuthor[(string) $author['name']] = \array_map(
                static fn (array $book): string => (string) $book['title'],
                $author['books'] ?? []
            );
        }

        \ksort($booksByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['PHP Patterns'],
                'Alan Turing' => ['Zed Algorithms'],
            ],
            $booksByAuthor
        );
    }

    public function test_cursor_pagination_applies_seek_predicate_to_top_level_collections(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($cursor: Cursor!) {
              books(
                queryParams: {
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  after: $cursor
                  limit: 2
                }
              ) {
                title
              }
            }
            GRAPHQL,
            [],
            [
                'cursor' => $this->cursor([
                    'title' => 'graphql basics',
                    'id' => $this->firstBookId,
                ]),
            ]
        );

        $this->assertNoErrors($result);

        self::assertSame(
            ['GraphQL in Action', 'PHP Patterns'],
            \array_map(
                static fn (array $book): string => (string) $book['title'],
                $result['data']['books'] ?? []
            )
        );
    }

    public function test_before_cursor_with_limit_returns_items_nearest_the_cursor(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($cursor: Cursor!) {
              books(
                queryParams: {
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  before: $cursor
                  limit: 1
                }
              ) {
                title
              }
            }
            GRAPHQL,
            [],
            [
                'cursor' => $this->cursor([
                    'title' => 'php patterns',
                    'id' => $this->bookIdByTitle('PHP Patterns'),
                ]),
            ]
        );

        $this->assertNoErrors($result);

        self::assertSame(
            ['GraphQL in Action'],
            \array_map(
                static fn (array $book): string => (string) $book['title'],
                $result['data']['books'] ?? []
            )
        );
    }

    public function test_nested_collection_cursor_pagination_reuses_per_parent_limit_walker(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($cursor: Cursor!) {
              authors {
                name
                books(
                  queryParams: {
                    ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                    after: $cursor
                    limit: 1
                  }
                ) {
                  title
                }
              }
            }
            GRAPHQL,
            [],
            [
                'cursor' => $this->cursor([
                    'title' => 'graphql basics',
                    'id' => $this->firstBookId,
                ]),
            ]
        );

        $this->assertNoErrors($result);

        $booksByAuthor = [];

        foreach (($result['data']['authors'] ?? []) as $author) {
            $booksByAuthor[(string) $author['name']] = \array_map(
                static fn (array $book): string => (string) $book['title'],
                $author['books'] ?? []
            );
        }

        \ksort($booksByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['PHP Patterns'],
                'Alan Turing' => ['GraphQL in Action'],
            ],
            $booksByAuthor
        );
    }

    public function test_nested_collection_before_cursor_with_limit_returns_items_nearest_the_cursor_per_parent(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($cursor: Cursor!) {
              authors {
                name
                books(
                  queryParams: {
                    ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                    before: $cursor
                    limit: 1
                  }
                ) {
                  title
                }
              }
            }
            GRAPHQL,
            [],
            [
                'cursor' => $this->cursor([
                    'title' => 'zed algorithms',
                    'id' => $this->bookIdByTitle('Zed Algorithms'),
                ]),
            ]
        );

        $this->assertNoErrors($result);

        $booksByAuthor = [];

        foreach (($result['data']['authors'] ?? []) as $author) {
            $booksByAuthor[(string) $author['name']] = \array_map(
                static fn (array $book): string => (string) $book['title'],
                $author['books'] ?? []
            );
        }

        \ksort($booksByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['PHP Patterns'],
                'Alan Turing' => ['GraphQL in Action'],
            ],
            $booksByAuthor
        );
    }

    public function test_cursor_pagination_rejects_page_parameter(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($cursor: Cursor!) {
              books(
                queryParams: {
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  after: $cursor
                  limit: 2
                  page: 1
                }
              ) {
                title
              }
            }
            GRAPHQL,
            [],
            [
                'cursor' => $this->cursor([
                    'title' => 'graphql basics',
                    'id' => $this->firstBookId,
                ]),
            ]
        );

        $this->assertErrorContains($result, 'page parameter cannot be combined with cursor pagination');
    }

    public function test_cursor_pagination_requires_cursor_ordering_metadata(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query($cursor: Cursor!) {
              books(
                queryParams: {
                  ordering: { legacyTitleAsc: { rank: 1, params: { direction: "ASC" } } }
                  after: $cursor
                  limit: 2
                }
              ) {
                title
              }
            }
            GRAPHQL,
            [],
            [
                'cursor' => $this->cursor([
                    'title' => 'graphql basics',
                    'id' => $this->firstBookId,
                ]),
            ]
        );

        $this->assertErrorContains($result, 'requires cursor-capable ordering metadata');
    }

    public function test_cursor_pagination_accepts_custom_cursor_scalar_types_that_parse_to_arrays(): void
    {
        $workspace = new FixtureWorkspace(prefix: 'watchtower_custom_cursor_');

        try {
            $schema = \str_replace(
                'scalar Cursor',
                'scalar Cursor
        scalar BookCursor',
                self::schemaSource()
            );

            $schema = \preg_replace(
                '/(input BooksQueryParams \{[^}]*\bafter: )Cursor(\b[^}]*\bbefore: )Cursor(\b[^}]*\})/s',
                '$1BookCursor$2BookCursor$3',
                $schema
            );

            self::assertIsString($schema);

            $workspace->writeSchema($schema);

            \file_put_contents(
                $this->workspace->scalarTypeDefinitionsDirectory().'/book_cursor_type_definition.php',
                <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Wedrix\Watchtower\BookCursorTypeDefinition;

                use GraphQL\Language\AST\StringValueNode;

                function serialize(array|string $value): string
                {
                    return \is_string($value)
                        ? $value
                        : \base64_encode(\json_encode($value, \JSON_THROW_ON_ERROR));
                }

                function parseValue(string $value): array
                {
                    $json = \base64_decode($value, true);

                    if ($json === false) {
                        throw new \Wedrix\Watchtower\InvalidValueCursorScalarTypeDefinitionException('Invalid BookCursor value!');
                    }

                    $decoded = \json_decode($json, true);

                    if (! \is_array($decoded)) {
                        throw new \Wedrix\Watchtower\InvalidValueCursorScalarTypeDefinitionException('Invalid BookCursor value!');
                    }

                    return $decoded;
                }

                function parseLiteral(StringValueNode $value, ?array $variables = null): array
                {
                    return parseValue($value->value);
                }
                PHP
            );

            $result = Executor(
                entityManager: $this->entityManager,
                schemaFile: $workspace->schemaFile(),
                pluginsDirectory: $this->workspace->pluginsDirectory(),
                scalarTypeDefinitionsDirectory: $this->workspace->scalarTypeDefinitionsDirectory(),
                cacheDirectory: $workspace->cacheDirectory(),
                optimize: false
            )->executeQuery(
                source: <<<'GRAPHQL'
                query($cursor: BookCursor!) {
                  books(
                    queryParams: {
                      ordering: { titleAsc: { rank: 1 } }
                      after: $cursor
                      limit: 2
                    }
                  ) {
                    title
                  }
                }
                GRAPHQL,
                rootValue: [],
                contextValue: $this->defaultContext(),
                variableValues: [
                    'cursor' => $this->cursor([
                        'title' => 'graphql basics',
                        'id' => $this->firstBookId,
                    ]),
                ],
                operationName: null,
                validationRules: null
            )->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

            $this->assertNoErrors($result);

            self::assertSame(
                ['GraphQL in Action', 'PHP Patterns'],
                \array_map(
                    static fn (array $book): string => (string) $book['title'],
                    $result['data']['books'] ?? []
                )
            );
        } finally {
            $workspace->cleanup();
        }
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
        $this->expectException(MissingSchemaCacheSchemaException::class);
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

            use Doctrine\\DBAL\\ParameterType;
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
                \$idOrderAlias = \$queryBuilder->reconciledAlias('idOrder');

                \$queryBuilder
                    ->addSelect("LOWER(\$entityAlias.title) AS HIDDEN \$orderAlias")
                    ->addSelect("\$entityAlias.id AS HIDDEN \$idOrderAlias")
                    ->addOrderBy(\$orderAlias, \$direction)
                    ->addOrderBy(\$idOrderAlias, 'ASC');

                \$queryBuilder->registerCursorOrdering('title', "LOWER(\$entityAlias.title)", \$direction, ParameterType::STRING);
                \$queryBuilder->registerCursorOrdering('id', "\$entityAlias.id", 'ASC', ParameterType::INTEGER);
            }
            PHP
        );

        $console->addOrderingPlugin('Book', 'legacyTitleAsc');
        $legacyOrderingPlugin = OrderingPlugin('Book', 'legacyTitleAsc');
        $this->writePluginFile(
            $plugins->filePath($legacyOrderingPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\OrderingPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\QueryBuilder;

            function {$legacyOrderingPlugin->name()}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
            {
                \$direction = \\strtoupper((string) (\$node->args()['queryParams']['ordering']['legacyTitleAsc']['params']['direction'] ?? 'ASC'));
                \$direction = \\in_array(\$direction, ['ASC', 'DESC'], true) ? \$direction : 'ASC';

                \$entityAlias = \$queryBuilder->rootEntityAlias();
                \$orderAlias = \$queryBuilder->reconciledAlias('legacyTitleOrder');

                \$queryBuilder
                    ->addSelect("LOWER(\$entityAlias.title) AS HIDDEN \$orderAlias")
                    ->addOrderBy(\$orderAlias, \$direction);
            }
            PHP
        );

        $console->addSearchResolverPlugin('Book');
        $searchResolverPlugin = SearchResolverPlugin('Book');
        $this->writePluginFile(
            $plugins->filePath($searchResolverPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\SearchResolverPlugin;

            use Doctrine\\ORM\\EntityManagerInterface;
            use Watchtower\\Tests\\Support\\Fixtures\\Entity\\Book;
            use Wedrix\\Watchtower\\Resolver\\Node;

            function {$searchResolverPlugin->name()}(
                Node \$node
            ): mixed
            {
                \$search = (string) (\$node->args()['queryParams']['search'] ?? '');
                \$entityManager = \$node->context()['entityManager'] ?? null;

                if (!\$entityManager instanceof EntityManagerInterface) {
                    throw new \\RuntimeException('Expected entity manager in context.');
                }

                \$books = \$entityManager->createQueryBuilder()
                    ->select('book')
                    ->from(Book::class, 'book')
                    ->where('LOWER(book.title) LIKE :search')
                    ->setParameter('search', '%' . \\strtolower(\$search) . '%')
                    ->orderBy('book.title', 'ASC')
                    ->getQuery()
                    ->getResult();

                return \\array_map(
                    static fn (Book \$book): array => [
                        'id' => \$book->getId(),
                        'title' => \$book->getTitle(),
                        'price' => \$book->getPrice(),
                    ],
                    \$books
                );
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

    private function createExecutor(
        bool $optimize
    ): Executor {
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

    /**
     * @param  array<string,mixed>  $values
     */
    private function cursor(
        array $values
    ): string {
        return \base64_encode(\json_encode($values, \JSON_THROW_ON_ERROR));
    }

    private function bookIdByTitle(
        string $title
    ): int {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('book.id')
            ->from(Book::class, 'book')
            ->where('book.title = :title')
            ->setParameter('title', $title)
            ->getQuery()
            ->getSingleScalarResult();
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
        scalar Cursor

        directive @watchtowerAssociation(through: String!) on FIELD_DEFINITION

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
          recommendedBooks(queryParams: BooksQueryParams): [Book!]!
            @watchtowerAssociation(through: "bookRecommendations")
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
          recommendingAuthors(queryParams: AuthorsQueryParams): [Author!]!
            @watchtowerAssociation(through: "bookRecommendations")
          ambiguousRecommendingAuthors(queryParams: AuthorsQueryParams): [Author!]!
            @watchtowerAssociation(through: "ambiguousBookRecommendations")
          missingRecommendingAuthors(queryParams: AuthorsQueryParams): [Author!]!
            @watchtowerAssociation(through: "missingAssociation")
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
          after: Cursor
          before: Cursor
          distinct: Boolean
        }

        input BooksQueryParams {
          filters: BooksQueryFilters
          ordering: BooksQueryOrdering
          search: String
          limit: Limit
          page: Page
          after: Cursor
          before: Cursor
          distinct: Boolean
        }

        input AuthorProfilesQueryParams {
          limit: Limit
          page: Page
          after: Cursor
          before: Cursor
          distinct: Boolean
        }

        input TagsQueryParams {
          limit: Limit
          page: Page
          after: Cursor
          before: Cursor
          distinct: Boolean
        }

        input BooksQueryFilters {
          titleContains: String
          unimplemented: String
        }

        input BooksQueryOrdering {
          titleAsc: BooksOrderingRule
          legacyTitleAsc: BooksOrderingRule
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
