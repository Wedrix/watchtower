<?php

declare(strict_types=1);

namespace Watchtower\Tests;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Error\DebugFlag;
use PHPUnit\Framework\TestCase;
use Watchtower\Tests\Support\DoctrineEntityManagerFactory;
use Watchtower\Tests\Support\FixtureWorkspace;

use function Wedrix\Watchtower\Executor;

/**
 * @group executor
 */
final class UnidirectionalOwningAssociationTest extends TestCase
{
    private FixtureWorkspace $workspace;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = new FixtureWorkspace(
            prefix: 'watchtower_unidirectional_',
            fixedIdentifier: 'watchtower_unidirectional_workspace'
        );
        $this->entityManager = DoctrineEntityManagerFactory::create(
            __DIR__.'/Support/Fixtures/mappings_unidirectional'
        );

        $this->workspace->seedLibraryData($this->entityManager);
        $this->workspace->writeSchema(self::schemaSource());
        $this->workspace->writeDefaultScalarTypeDefinitions();
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
        $this->workspace->cleanup();

        parent::tearDown();
    }

    public function test_unidirectional_many_to_many_from_owning_side_is_resolved(): void
    {
        $result = $this->createExecutor()
            ->executeQuery(
                source: <<<'GRAPHQL'
                query {
                  books {
                    title
                    tags {
                      name
                    }
                  }
                }
                GRAPHQL,
                rootValue: [],
                contextValue: ['entityManager' => $this->entityManager],
                variableValues: null,
                operationName: null,
                validationRules: null
            )
            ->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

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
    }

    public function test_unidirectional_many_to_one_from_owning_side_is_resolved(): void
    {
        $result = $this->createExecutor()
            ->executeQuery(
                source: <<<'GRAPHQL'
                query {
                  books {
                    title
                    author {
                      name
                    }
                  }
                }
                GRAPHQL,
                rootValue: [],
                contextValue: ['entityManager' => $this->entityManager],
                variableValues: null,
                operationName: null,
                validationRules: null
            )
            ->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

        $this->assertNoErrors($result);

        $books = $result['data']['books'] ?? [];
        $authorsByBook = [];

        foreach ($books as $book) {
            $bookTitle = (string) ($book['title'] ?? '');
            $authorName = (string) ($book['author']['name'] ?? '');
            $authorsByBook[$bookTitle] = $authorName;
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

    public function test_unidirectional_one_to_one_from_owning_side_is_resolved(): void
    {
        $result = $this->createExecutor()
            ->executeQuery(
                source: <<<'GRAPHQL'
                query {
                  authorProfiles {
                    bio
                    author {
                      name
                    }
                  }
                }
                GRAPHQL,
                rootValue: [],
                contextValue: ['entityManager' => $this->entityManager],
                variableValues: null,
                operationName: null,
                validationRules: null
            )
            ->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);

        $this->assertNoErrors($result);

        $authorProfiles = $result['data']['authorProfiles'] ?? [];
        $authorsByBio = [];

        foreach ($authorProfiles as $authorProfile) {
            $bio = (string) ($authorProfile['bio'] ?? '');
            $authorName = (string) ($authorProfile['author']['name'] ?? '');
            $authorsByBio[$bio] = $authorName;
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

    private function createExecutor(): \Wedrix\Watchtower\Executor
    {
        return Executor(
            entityManager: $this->entityManager,
            schemaFile: $this->workspace->schemaFile(),
            pluginsDirectory: $this->workspace->pluginsDirectory(),
            scalarTypeDefinitionsDirectory: $this->workspace->scalarTypeDefinitionsDirectory(),
            cacheDirectory: $this->workspace->cacheDirectory(),
            optimize: false
        );
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

    private static function schemaSource(): string
    {
        return <<<'GRAPHQL'
        type Query {
          books: [Book!]!
          authorProfiles: [AuthorProfile!]!
        }

        type Book {
          id: ID!
          title: String!
          author: Author!
          tags: [Tag!]!
        }

        type Tag {
          id: ID!
          name: String!
        }

        type Author {
          id: ID!
          name: String!
        }

        type AuthorProfile {
          id: ID!
          bio: String!
          author: Author!
        }
        GRAPHQL;
    }
}
