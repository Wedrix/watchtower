<?php

declare(strict_types=1);

namespace Watchtower\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Watchtower\Tests\Support\Fixtures\Entity\Author;
use Watchtower\Tests\Support\Fixtures\Entity\AuthorProfile;
use Watchtower\Tests\Support\Fixtures\Entity\Book;
use Watchtower\Tests\Support\Fixtures\Entity\Tag;

use function Wedrix\Watchtower\DateTimeScalarTypeDefinition;
use function Wedrix\Watchtower\LimitScalarTypeDefinition;
use function Wedrix\Watchtower\PageScalarTypeDefinition;

final class FixtureWorkspace
{
    private string $rootDirectory;

    private string $schemaDirectory;

    private string $pluginsDirectory;

    private string $scalarTypeDefinitionsDirectory;

    private string $cacheDirectory;

    private string $schemaFileName = 'schema.graphql';

    private bool $cleaned = false;

    public function __construct(
        string $prefix = 'watchtower_fixture_',
        ?string $fixedIdentifier = null
    ) {
        $this->rootDirectory = $fixedIdentifier
            ? \sys_get_temp_dir().'/'.$fixedIdentifier
            : \sys_get_temp_dir().'/'.$prefix.\bin2hex(\random_bytes(8));

        if ($fixedIdentifier && \is_dir($this->rootDirectory)) {
            self::deleteDirectory($this->rootDirectory);
        }

        $this->schemaDirectory = $this->rootDirectory.'/schema';
        $this->pluginsDirectory = $this->rootDirectory.'/plugins';
        $this->scalarTypeDefinitionsDirectory = $this->rootDirectory.'/scalar_type_definitions';
        $this->cacheDirectory = $this->rootDirectory.'/cache';

        self::ensureDirectory($this->schemaDirectory);
        self::ensureDirectory($this->pluginsDirectory);
        self::ensureDirectory($this->scalarTypeDefinitionsDirectory);
        self::ensureDirectory($this->cacheDirectory);
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    public function rootDirectory(): string
    {
        return $this->rootDirectory;
    }

    public function schemaDirectory(): string
    {
        return $this->schemaDirectory;
    }

    public function schemaFileName(): string
    {
        return $this->schemaFileName;
    }

    public function schemaFile(): string
    {
        return $this->schemaDirectory.'/'.$this->schemaFileName;
    }

    public function pluginsDirectory(): string
    {
        return $this->pluginsDirectory;
    }

    public function scalarTypeDefinitionsDirectory(): string
    {
        return $this->scalarTypeDefinitionsDirectory;
    }

    public function cacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    public function writeSchema(
        string $schemaSource
    ): void
    {
        \file_put_contents($this->schemaFile(), $schemaSource);
    }

    public function writeDefaultScalarTypeDefinitions(): void
    {
        \file_put_contents(
            $this->scalarTypeDefinitionsDirectory.'/date_time_type_definition.php',
            DateTimeScalarTypeDefinition()->template()
        );
        \file_put_contents(
            $this->scalarTypeDefinitionsDirectory.'/limit_type_definition.php',
            LimitScalarTypeDefinition()->template()
        );
        \file_put_contents(
            $this->scalarTypeDefinitionsDirectory.'/page_type_definition.php',
            PageScalarTypeDefinition()->template()
        );
    }

    /**
     * @return array{authors:array<int,Author>,profiles:array<int,AuthorProfile>,books:array<int,Book>,tags:array<int,Tag>}
     */
    public function seedLibraryData(
        EntityManagerInterface $entityManager
    ): array
    {
        $authorOne = new Author('Ada Lovelace');
        $authorTwo = new Author('Alan Turing');

        $profileOne = new AuthorProfile('First computer programmer and analytical engine pioneer.');
        $profileTwo = new AuthorProfile('Computer scientist and father of theoretical computing.');
        $profileOne->setAuthor($authorOne);
        $profileTwo->setAuthor($authorTwo);

        $bookOne = new Book(
            $authorOne,
            'GraphQL Basics',
            12.50,
            new \DateTimeImmutable('2024-01-01T00:00:00+00:00')
        );
        $bookTwo = new Book(
            $authorOne,
            'PHP Patterns',
            18.00,
            new \DateTimeImmutable('2024-01-10T00:00:00+00:00')
        );
        $bookThree = new Book(
            $authorTwo,
            'GraphQL in Action',
            15.75,
            new \DateTimeImmutable('2024-01-20T00:00:00+00:00')
        );
        $bookFour = new Book(
            $authorTwo,
            'Zed Algorithms',
            21.99,
            new \DateTimeImmutable('2024-02-01T00:00:00+00:00')
        );

        $tagGraphql = new Tag('graphql');
        $tagPhp = new Tag('php');
        $tagAlgorithms = new Tag('algorithms');

        $bookOne->addTag($tagGraphql);
        $bookOne->addTag($tagPhp);
        $bookTwo->addTag($tagPhp);
        $bookThree->addTag($tagGraphql);
        $bookFour->addTag($tagAlgorithms);

        foreach ([
            $authorOne,
            $authorTwo,
            $profileOne,
            $profileTwo,
            $bookOne,
            $bookTwo,
            $bookThree,
            $bookFour,
            $tagGraphql,
            $tagPhp,
            $tagAlgorithms,
        ] as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();

        return [
            'authors' => [$authorOne, $authorTwo],
            'profiles' => [$profileOne, $profileTwo],
            'books' => [$bookOne, $bookTwo, $bookThree, $bookFour],
            'tags' => [$tagGraphql, $tagPhp, $tagAlgorithms],
        ];
    }

    public function cleanup(): void
    {
        if ($this->cleaned || !\is_dir($this->rootDirectory)) {
            return;
        }

        self::deleteDirectory($this->rootDirectory);
        $this->cleaned = true;
    }

    private static function ensureDirectory(
        string $directory
    ): void
    {
        if (\is_dir($directory)) {
            return;
        }

        if (!\mkdir($directory, 0777, true) && !\is_dir($directory)) {
            throw new \RuntimeException("Unable to create fixture directory '{$directory}'.");
        }
    }

    private static function deleteDirectory(
        string $directory
    ): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $items = \scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (\is_dir($path)) {
                self::deleteDirectory($path);
            } else {
                \unlink($path);
            }
        }

        \rmdir($directory);
    }
}
