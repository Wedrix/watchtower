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
use Wedrix\Watchtower\ReservedFieldNameEntityException;
use Wedrix\Watchtower\Resolver\ConflictingJoinAliasQueryBuilderException;
use Wedrix\Watchtower\Resolver\DuplicateJoinPathQueryBuilderException;
use Wedrix\Watchtower\SyncedQuerySchema;

use function Wedrix\Watchtower\AuthorizorPlugin;
use function Wedrix\Watchtower\Console;
use function Wedrix\Watchtower\ConstraintPlugin;
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

    public function test_query_builder_reuses_join_aliases_for_matching_join_specs(): void
    {
        $queryBuilder = \Wedrix\Watchtower\Resolver\QueryBuilder($this->entityManager->createQueryBuilder());
        $queryBuilder
            ->select('__root.title')
            ->from(Book::class, '__root');

        $authorAlias = $queryBuilder->joinAlias('__root.author', 'bookAuthor');
        $queryBuilder->joinOnce('__root.author', $authorAlias);
        $sameAuthorAlias = $queryBuilder->joinAlias('__root.author', 'ignoredAuthorAlias');
        $queryBuilder->joinOnce('__root.author', $sameAuthorAlias);

        $tagsAlias = $queryBuilder->leftJoinAlias('__root.tags', 'bookTags');
        $queryBuilder->leftJoinOnce('__root.tags', $tagsAlias);
        $sameTagsAlias = $queryBuilder->leftJoinAlias('__root.tags', 'ignoredTagsAlias');
        $queryBuilder->leftJoinOnce('__root.tags', $sameTagsAlias);

        self::assertSame('bookAuthor', $authorAlias);
        self::assertSame($authorAlias, $sameAuthorAlias);
        self::assertSame('bookTags', $tagsAlias);
        self::assertSame($tagsAlias, $sameTagsAlias);
        self::assertSame([], $queryBuilder->duplicateJoinPaths());

        $dql = $queryBuilder->getDQL();

        self::assertSame(1, \preg_match_all('/\bJOIN\s+__root\.author\s+bookAuthor\b/', $dql));
        self::assertSame(1, \preg_match_all('/\bLEFT\s+JOIN\s+__root\.tags\s+bookTags\b/', $dql));
    }

    public function test_query_builder_names_alias_and_parameter_namespaces_explicitly(): void
    {
        $queryBuilder = \Wedrix\Watchtower\Resolver\QueryBuilder($this->entityManager->createQueryBuilder());

        self::assertSame('shared', $queryBuilder->joinAlias('__root.author', 'shared'));
        self::assertSame('shared2', $queryBuilder->selectAlias('shared'));
        self::assertSame('shared', $queryBuilder->parameterName('shared'));
        self::assertSame('shared2', $queryBuilder->parameterName('shared'));
    }

    public function test_query_builder_join_once_rejects_existing_join_with_different_alias(): void
    {
        $queryBuilder = \Wedrix\Watchtower\Resolver\QueryBuilder($this->entityManager->createQueryBuilder());
        $queryBuilder
            ->select('__root.title')
            ->from(Book::class, '__root');

        $queryBuilder->joinOnce('__root.author', 'bookAuthor');

        $this->expectException(ConflictingJoinAliasQueryBuilderException::class);

        $queryBuilder->joinOnce('__root.author', 'otherBookAuthor');
    }

    public function test_query_builder_reuses_pending_join_alias_before_join_is_added(): void
    {
        $queryBuilder = \Wedrix\Watchtower\Resolver\QueryBuilder($this->entityManager->createQueryBuilder());
        $queryBuilder
            ->select('__root.title')
            ->from(Book::class, '__root');

        $authorAlias = $queryBuilder->joinAlias('__root.author', 'bookAuthor');
        $sameAuthorAlias = $queryBuilder->joinAlias('__root.author', 'ignoredAuthorAlias');

        self::assertSame($authorAlias, $sameAuthorAlias);

        $queryBuilder->joinOnce('__root.author', $sameAuthorAlias);

        self::assertSame(1, \preg_match_all('/\bJOIN\s+__root\.author\s+bookAuthor\b/', $queryBuilder->getDQL()));
    }

    public function test_query_builder_tracks_doctrine_inner_join_aliases(): void
    {
        $queryBuilder = \Wedrix\Watchtower\Resolver\QueryBuilder($this->entityManager->createQueryBuilder());
        $queryBuilder
            ->select('__root.title')
            ->from(Book::class, '__root');

        $queryBuilder->innerJoin('__root.author', 'innerAuthor');

        self::assertSame('innerAuthor', $queryBuilder->joinAlias('__root.author', 'ignoredAuthorAlias'));

        $queryBuilder->join('__root.author', 'duplicateAuthor');

        self::assertSame(
            [
                '__root.author' => ['innerAuthor', 'duplicateAuthor'],
            ],
            $queryBuilder->duplicateJoinPaths()
        );
    }

    public function test_query_builder_reports_duplicate_join_paths_for_development_shape_checks(): void
    {
        $queryBuilder = \Wedrix\Watchtower\Resolver\QueryBuilder($this->entityManager->createQueryBuilder());
        $queryBuilder
            ->select('__root.title')
            ->from(Book::class, '__root');

        $queryBuilder->join('__root.author', 'firstAuthor');
        $queryBuilder->join('__root.author', 'secondAuthor');

        self::assertSame(
            [
                '__root.author' => ['firstAuthor', 'secondAuthor'],
            ],
            $queryBuilder->duplicateJoinPaths()
        );

        $this->expectException(DuplicateJoinPathQueryBuilderException::class);

        $queryBuilder->assertNoDuplicateJoins();
    }

    public function test_query_builder_allows_repeated_join_path_with_different_conditions(): void
    {
        $queryBuilder = \Wedrix\Watchtower\Resolver\QueryBuilder($this->entityManager->createQueryBuilder());
        $queryBuilder
            ->select('__root.title')
            ->from(Book::class, '__root');

        $queryBuilder->join('__root.author', 'namedAuthor', 'WITH', "namedAuthor.name = 'Ada Lovelace'");
        $queryBuilder->join('__root.author', 'knownAuthor', 'WITH', 'knownAuthor.id IS NOT NULL');

        self::assertSame([], $queryBuilder->duplicateJoinPaths());

        $queryBuilder->assertNoDuplicateJoins();
    }

    public function test_composed_plugins_reuse_join_once_alias_for_generated_query_shape(): void
    {
        $console = $this->createConsole();
        $plugins = $console->plugins();

        $console->addConstraintPlugin('Book');
        $constraintPlugin = ConstraintPlugin('Book');
        $this->writePluginFile(
            $plugins->filePath($constraintPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\ConstraintPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\QueryBuilder;

            function {$constraintPlugin->name()}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
            {
                \$queryBuilder->joinOnce(
                    \$queryBuilder->rootAlias() . '.author',
                    'bookAuthor'
                );
            }
            PHP
        );

        $console->addFilterPlugin('Book', 'authorNameContains');
        $filterPlugin = FilterPlugin('Book', 'authorNameContains');
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
                \$needle = \$node->args()['queryParams']['filters']['authorNameContains'] ?? null;

                if (!\\is_string(\$needle) || \$needle === '') {
                    return;
                }

                \$authorJoin = \$queryBuilder->rootAlias() . '.author';
                \$authorAlias = \$queryBuilder->joinAlias(
                    \$authorJoin,
                    'filteredBookAuthor'
                );
                \$queryBuilder->joinOnce(\$authorJoin, \$authorAlias);
                \$needleParameter = \$queryBuilder->parameterName('authorNameContains');

                \$queryBuilder
                    ->andWhere("LOWER(\$authorAlias.name) LIKE :\$needleParameter")
                    ->setParameter(\$needleParameter, '%' . \\strtolower(\$needle) . '%');
            }
            PHP
        );

        $console->addOrderingPlugin('Book', 'authorNameAsc');
        $orderingPlugin = OrderingPlugin('Book', 'authorNameAsc');
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
                \$rootAlias = \$queryBuilder->rootAlias();
                \$authorJoin = \$rootAlias . '.author';
                \$authorAlias = \$queryBuilder->joinAlias(
                    \$authorJoin,
                    'orderedBookAuthor'
                );
                \$queryBuilder->joinOnce(\$authorJoin, \$authorAlias);
                \$authorNameAlias = \$queryBuilder->selectAlias('authorNameOrder');
                \$idAlias = \$queryBuilder->selectAlias('authorNameIdOrder');

                \$queryBuilder
                    ->addSelect("LOWER(\$authorAlias.name) AS HIDDEN \$authorNameAlias")
                    ->addSelect("\$rootAlias.id AS HIDDEN \$idAlias")
                    ->addOrderBy(\$authorNameAlias, 'ASC')
                    ->addOrderBy(\$idAlias, 'ASC');

                \$queryBuilder->registerCursorOrdering('authorName', "LOWER(\$authorAlias.name)", 'ASC', ParameterType::STRING);
                \$queryBuilder->registerCursorOrdering('id', "\$rootAlias.id", 'ASC', ParameterType::INTEGER);

                \$recorder = \$node->context()['queryShapeRecorder'] ?? null;

                if (\$recorder instanceof \\ArrayObject) {
                    \$recorder['dql'] = \$queryBuilder->getDQL();
                    \$recorder['duplicateJoinPaths'] = \$queryBuilder->duplicateJoinPaths();
                }
            }
            PHP
        );

        $recorder = new \ArrayObject;
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(
                queryParams: {
                  filters: { authorNameContains: "ada" }
                  ordering: { authorNameAsc: { rank: 1 } }
                }
              ) {
                title
              }
            }
            GRAPHQL,
            [
                'queryShapeRecorder' => $recorder,
            ]
        );

        $this->assertNoErrors($result);

        self::assertSame(
            ['GraphQL Basics', 'PHP Patterns'],
            \array_map(
                static fn (array $book): string => (string) $book['title'],
                $result['data']['books'] ?? []
            )
        );

        $dql = (string) ($recorder['dql'] ?? '');

        self::assertSame([], $recorder['duplicateJoinPaths'] ?? null);
        self::assertSame(1, \preg_match_all('/\bJOIN\s+__root\.author\s+bookAuthor\b/', $dql));
        self::assertStringNotContainsString('filteredBookAuthor', $dql);
        self::assertStringNotContainsString('orderedBookAuthor', $dql);
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

    public function test_schema_declared_through_association_reuses_existing_join_alias(): void
    {
        $console = $this->createConsole();
        $plugins = $console->plugins();

        $console->addFilterPlugin('Book', 'throughRankAtLeast');
        $filterPlugin = FilterPlugin('Book', 'throughRankAtLeast');
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
                \$rank = \$node->args()['queryParams']['filters']['throughRankAtLeast'] ?? null;

                if (!\\is_int(\$rank)) {
                    return;
                }

                \$throughJoin = \$queryBuilder->rootAlias() . '.bookRecommendations';
                \$throughAlias = \$queryBuilder->joinAlias(
                    \$throughJoin,
                    'positionBookRecommendation'
                );
                \$queryBuilder->joinOnce(\$throughJoin, \$throughAlias);

                \$rankParameter = \$queryBuilder->parameterName('recommendationRank');

                \$queryBuilder
                    ->andWhere("\$throughAlias.rank >= :\$rankParameter")
                    ->setParameter(\$rankParameter, \$rank);
            }
            PHP
        );

        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              authors {
                name
                recommendedBooks(
                  queryParams: {
                    filters: { throughRankAtLeast: 1 }
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

            \sort($recommendedBooksByAuthor[(string) $author['name']]);
        }

        \ksort($recommendedBooksByAuthor);

        self::assertSame(
            [
                'Ada Lovelace' => ['GraphQL Basics', 'PHP Patterns'],
                'Alan Turing' => ['GraphQL Basics', 'Zed Algorithms'],
            ],
            $recommendedBooksByAuthor
        );
    }

    public function test_parent_association_reuses_existing_framework_parent_join_alias(): void
    {
        $console = $this->createConsole();
        $plugins = $console->plugins();

        $console->addConstraintPlugin('Author');
        $constraintPlugin = ConstraintPlugin('Author');
        $this->writePluginFile(
            $plugins->filePath($constraintPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\ConstraintPlugin;

            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\QueryBuilder;

            function {$constraintPlugin->name()}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
            {
                if (\$node->isTopLevel()) {
                    return;
                }

                \$queryBuilder->joinOnce(
                    \$queryBuilder->rootAlias() . '.books',
                    \$queryBuilder->parentAlias()
                );
            }
            PHP
        );

        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books {
                title
                author {
                  name
                }
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        $authorsByBook = [];

        foreach (($result['data']['books'] ?? []) as $book) {
            $authorsByBook[(string) $book['title']] = (string) ($book['author']['name'] ?? '');
        }

        \ksort($authorsByBook);

        self::assertSame(
            [
                'GraphQL Basics' => 'Ada Lovelace',
                'GraphQL in Action' => 'Alan Turing',
                'PHP Patterns' => 'Ada Lovelace',
                'Zed Algorithms' => 'Alan Turing',
            ],
            $authorsByBook
        );
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

    public function test_virtual_cursor_field_returns_cursor_values_for_ordered_collections(): void
    {
        $firstPageResult = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(
                queryParams: {
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  limit: 2
                }
              ) {
                _cursor
                id
                title
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($firstPageResult);

        $books = $firstPageResult['data']['books'] ?? [];

        self::assertCount(2, $books);
        self::assertSame((string) $this->firstBookId, $books[0]['id']);
        self::assertSame('GraphQL Basics', $books[0]['title']);
        self::assertSame('GraphQL in Action', $books[1]['title']);

        // Decode the scalar output to assert the generated cursor payload directly.
        $decodedCursor = \json_decode((string) \base64_decode((string) $books[0]['_cursor'], true), true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decodedCursor)) {
            throw new \RuntimeException('Expected decoded cursor to be an array.');
        }

        self::assertSame(
            [
                'title' => 'graphql basics',
                'id' => $this->firstBookId,
            ],
            $decodedCursor
        );

        $secondPageResult = $this->executeQuery(
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
                'cursor' => $books[1]['_cursor'],
            ]
        );

        $this->assertNoErrors($secondPageResult);

        self::assertSame(
            ['PHP Patterns', 'Zed Algorithms'],
            \array_map(
                static fn (array $book): string => (string) $book['title'],
                $secondPageResult['data']['books'] ?? []
            )
        );
    }

    public function test_virtual_cursor_field_does_not_reuse_non_cursor_batch_results(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              withoutCursor: books(
                queryParams: {
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  limit: 1
                }
              ) {
                title
              }
              withCursor: books(
                queryParams: {
                  ordering: { titleAsc: { rank: 1, params: { direction: "ASC" } } }
                  limit: 1
                }
              ) {
                _cursor
                title
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        self::assertSame('GraphQL Basics', $result['data']['withoutCursor'][0]['title'] ?? null);
        self::assertSame('GraphQL Basics', $result['data']['withCursor'][0]['title'] ?? null);

        $decodedCursor = \json_decode(
            (string) \base64_decode((string) ($result['data']['withCursor'][0]['_cursor'] ?? ''), true),
            true,
            flags: \JSON_THROW_ON_ERROR
        );

        if (! \is_array($decodedCursor)) {
            throw new \RuntimeException('Expected decoded cursor to be an array.');
        }

        self::assertSame(
            [
                'title' => 'graphql basics',
                'id' => $this->firstBookId,
            ],
            $decodedCursor
        );
    }

    public function test_virtual_cursor_field_normalizes_datetime_values_for_reuse(): void
    {
        $firstPageResult = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(
                queryParams: {
                  ordering: { publishedAtAsc: { rank: 1 } }
                  limit: 1
                }
              ) {
                _cursor
                title
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($firstPageResult);

        $cursor = (string) ($firstPageResult['data']['books'][0]['_cursor'] ?? '');
        $decodedCursor = \json_decode((string) \base64_decode($cursor, true), true, flags: \JSON_THROW_ON_ERROR);

        if (! \is_array($decodedCursor)) {
            throw new \RuntimeException('Expected decoded cursor to be an array.');
        }

        self::assertSame(
            [
                'publishedAt' => '2024-01-01T00:00:00+00:00',
                'id' => $this->firstBookId,
            ],
            $decodedCursor
        );

        $secondPageResult = $this->executeQuery(
            <<<'GRAPHQL'
            query($cursor: Cursor!) {
              books(
                queryParams: {
                  ordering: { publishedAtAsc: { rank: 1 } }
                  after: $cursor
                  limit: 1
                }
              ) {
                title
              }
            }
            GRAPHQL,
            [],
            [
                'cursor' => $cursor,
            ]
        );

        $this->assertNoErrors($secondPageResult);

        self::assertSame('PHP Patterns', $secondPageResult['data']['books'][0]['title'] ?? null);
    }

    public function test_virtual_cursor_field_is_null_without_cursor_ordering_metadata(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(queryParams: { limit: 1 }) {
                _cursor
                title
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        self::assertArrayHasKey('_cursor', $result['data']['books'][0] ?? []);
        self::assertNull($result['data']['books'][0]['_cursor']);
    }

    public function test_reserved_entity_cursor_field_cannot_be_overridden_by_resolver_plugin(): void
    {
        $console = $this->createConsole();
        $plugins = $console->plugins();

        $console->addResolverPlugin('Book', '_cursor');
        $resolverPlugin = ResolverPlugin('Book', '_cursor');
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
                return 'plugin-owned-cursor';
            }
            PHP
        );

        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(queryParams: { limit: 1 }) {
                pluginAttempt: _cursor
                title
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        self::assertSame('GraphQL Basics', $result['data']['books'][0]['title'] ?? null);
        self::assertArrayHasKey('pluginAttempt', $result['data']['books'][0] ?? []);
        self::assertNull($result['data']['books'][0]['pluginAttempt']);
    }

    public function test_reserved_entity_cursor_field_ignores_resolver_root_value(): void
    {
        $console = $this->createConsole();
        $plugins = $console->plugins();

        $console->addResolverPlugin('Query', 'book');
        $resolverPlugin = ResolverPlugin('Query', 'book');
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
                return [
                    'id' => (int) \$node->args()['id'],
                    '_cursor' => 'resolver-owned-cursor',
                    'title' => 'Resolver Book',
                ];
            }
            PHP
        );

        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              book(id: "1") {
                _cursor
                title
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        self::assertSame('Resolver Book', $result['data']['book']['title'] ?? null);
        self::assertArrayHasKey('_cursor', $result['data']['book'] ?? []);
        self::assertNull($result['data']['book']['_cursor']);
    }

    public function test_reserved_entity_cursor_field_ignores_search_resolver_root_value(): void
    {
        $result = $this->executeQuery(
            <<<'GRAPHQL'
            query {
              books(queryParams: { search: "graphql" }) {
                _cursor
                title
              }
            }
            GRAPHQL
        );

        $this->assertNoErrors($result);

        self::assertSame('GraphQL Basics', $result['data']['books'][0]['title'] ?? null);
        self::assertArrayHasKey('_cursor', $result['data']['books'][0] ?? []);
        self::assertNull($result['data']['books'][0]['_cursor']);
    }

    public function test_top_level_reserved_name_can_still_be_resolver_backed_when_not_entity_field(): void
    {
        $workspace = new FixtureWorkspace(prefix: 'watchtower_top_level_cursor_');

        try {
            $workspace->writeSchema(
                <<<'GRAPHQL'
                type Query {
                  _cursor: String!
                }
                GRAPHQL
            );

            $console = Console(
                entityManager: $this->entityManager,
                schemaFileDirectory: $workspace->schemaDirectory(),
                schemaFileName: $workspace->schemaFileName(),
                pluginsDirectory: $workspace->pluginsDirectory(),
                scalarTypeDefinitionsDirectory: $workspace->scalarTypeDefinitionsDirectory(),
                cacheDirectory: $workspace->cacheDirectory()
            );
            $plugins = $console->plugins();

            $console->addResolverPlugin('Query', '_cursor');
            $resolverPlugin = ResolverPlugin('Query', '_cursor');
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
                    return 'top-level-cursor';
                }
                PHP
            );

            $result = Executor(
                entityManager: $this->entityManager,
                schemaFile: $workspace->schemaFile(),
                pluginsDirectory: $workspace->pluginsDirectory(),
                scalarTypeDefinitionsDirectory: $workspace->scalarTypeDefinitionsDirectory(),
                cacheDirectory: $workspace->cacheDirectory(),
                optimize: false
            )->executeQuery(
                source: <<<'GRAPHQL'
                query {
                  _cursor
                }
                GRAPHQL,
                rootValue: [],
                contextValue: $this->defaultContext(),
                variableValues: null,
                operationName: null,
                validationRules: null
            )->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

            $this->assertNoErrors($result);

            self::assertSame('top-level-cursor', $result['data']['_cursor'] ?? null);
        } finally {
            $workspace->cleanup();
        }
    }

    public function test_synced_schema_rejects_entity_fields_using_reserved_names(): void
    {
        $entityManager = DoctrineEntityManagerFactory::create(
            __DIR__.'/Support/Fixtures/mappings_reserved',
            'Watchtower\\Tests\\Support\\Fixtures\\Reserved'
        );

        try {
            $this->expectException(ReservedFieldNameEntityException::class);
            $this->expectExceptionMessage('_cursor');

            new SyncedQuerySchema($entityManager);
        } finally {
            $entityManager->close();
        }
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
                \$rootAlias = \$queryBuilder->rootAlias();

                \$queryBuilder->addSelect("LENGTH(\$rootAlias.title) AS titleLength");
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

                \$rootAlias = \$queryBuilder->rootAlias();
                \$needleParameter = \$queryBuilder->parameterName('titleContains');

                \$queryBuilder
                    ->andWhere("LOWER(\$rootAlias.title) LIKE :\$needleParameter")
                    ->setParameter(\$needleParameter, '%' . \\strtolower(\$needle) . '%');
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

                \$rootAlias = \$queryBuilder->rootAlias();
                \$orderAlias = \$queryBuilder->selectAlias('titleOrder');
                \$idOrderAlias = \$queryBuilder->selectAlias('idOrder');

                \$queryBuilder
                    ->addSelect("LOWER(\$rootAlias.title) AS HIDDEN \$orderAlias")
                    ->addSelect("\$rootAlias.id AS HIDDEN \$idOrderAlias")
                    ->addOrderBy(\$orderAlias, \$direction)
                    ->addOrderBy(\$idOrderAlias, 'ASC');

                \$queryBuilder->registerCursorOrdering('title', "LOWER(\$rootAlias.title)", \$direction, ParameterType::STRING);
                \$queryBuilder->registerCursorOrdering('id', "\$rootAlias.id", 'ASC', ParameterType::INTEGER);
            }
            PHP
        );

        $console->addOrderingPlugin('Book', 'publishedAtAsc');
        $publishedAtOrderingPlugin = OrderingPlugin('Book', 'publishedAtAsc');

        $this->writePluginFile(
            $plugins->filePath($publishedAtOrderingPlugin),
            <<<PHP
            <?php

            declare(strict_types=1);

            namespace Wedrix\\Watchtower\\OrderingPlugin;

            use Doctrine\\DBAL\\ParameterType;
            use Wedrix\\Watchtower\\Resolver\\Node;
            use Wedrix\\Watchtower\\Resolver\\QueryBuilder;

            function {$publishedAtOrderingPlugin->name()}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
            {
                \$rootAlias = \$queryBuilder->rootAlias();
                \$publishedAtOrderAlias = \$queryBuilder->selectAlias('publishedAtOrder');
                \$idOrderAlias = \$queryBuilder->selectAlias('publishedAtIdOrder');

                \$queryBuilder
                    ->addSelect("\$rootAlias.publishedAt AS HIDDEN \$publishedAtOrderAlias")
                    ->addSelect("\$rootAlias.id AS HIDDEN \$idOrderAlias")
                    ->addOrderBy(\$publishedAtOrderAlias, 'ASC')
                    ->addOrderBy(\$idOrderAlias, 'ASC');

                \$queryBuilder->registerCursorOrdering('publishedAt', "\$rootAlias.publishedAt", 'ASC', ParameterType::STRING);
                \$queryBuilder->registerCursorOrdering('id', "\$rootAlias.id", 'ASC', ParameterType::INTEGER);
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

                \$rootAlias = \$queryBuilder->rootAlias();
                \$orderAlias = \$queryBuilder->selectAlias('legacyTitleOrder');

                \$queryBuilder
                    ->addSelect("LOWER(\$rootAlias.title) AS HIDDEN \$orderAlias")
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
                        '_cursor' => 'search-owned-cursor',
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
          _cursor: Cursor
          id: ID!
          name: String!
          profile: AuthorProfile
          books(queryParams: BooksQueryParams): [Book!]!
          recommendedBooks(queryParams: BooksQueryParams): [Book!]!
            @watchtowerAssociation(through: "bookRecommendations")
        }

        type AuthorProfile {
          _cursor: Cursor
          id: ID!
          bio: String!
          author: Author!
        }

        type Book {
          _cursor: Cursor
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
          _cursor: Cursor
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
          authorNameContains: String
          throughRankAtLeast: Int
          unimplemented: String
        }

        input BooksQueryOrdering {
          titleAsc: BooksOrderingRule
          publishedAtAsc: BooksOrderingRule
          legacyTitleAsc: BooksOrderingRule
          authorNameAsc: BooksOrderingRule
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
