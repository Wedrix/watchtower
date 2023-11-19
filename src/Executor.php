<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema as GraphQLTypeSchema;
use GraphQL\Validator\Rules\ValidationRule;

/**
 * @api
 */
final class Executor
{
    private readonly GraphQLTypeSchema $schema;

    private readonly Resolver $resolver;

    /**
     * @param EntityManagerInterface $entityManager The Doctrine entityManager instance.
     * @param string $schemaFile The schema file.
     * @param string $pluginsDirectory The plugin functions' directory.
     * @param string $scalarTypeDefinitionsDirectory The scalar types' definitions' directory.
     * @param bool $optimize Use the cache for improved perfomance. 
     *      Note: You must run Console::generateCache() to create the cache with the latest changes.
     * @param string $cacheDirectory The directory for storing cache files.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $schemaFile,
        private readonly string $pluginsDirectory,
        private readonly string $scalarTypeDefinitionsDirectory,
        private readonly string $cacheDirectory,
        private readonly bool $optimize
    )
    {
        $this->schema = new Schema(
            sourceFile: $this->schemaFile,
            scalarTypeDefinitions: new ScalarTypeDefinitions(
                directory: $this->scalarTypeDefinitionsDirectory,
                cacheDirectory: $this->cacheDirectory,
                optimize: $this->optimize
            ),
            cacheDirectory: $this->cacheDirectory,
            optimize: $this->optimize
        );

        $this->resolver = new Resolver(
            entityManager: $this->entityManager,
            plugins: new Plugins(
                directory: $this->pluginsDirectory,
                cacheDirectory: $this->cacheDirectory,
                optimize: $this->optimize
            )
        );
    }

    /**
     * @param array<string,mixed> $rootValue
     * @param array<string,mixed> $contextValue
     * @param array<mixed>|null $variableValues
     * @param array<ValidationRule>|null $validationRules
     */
    public function executeQuery(
        string|DocumentNode $source,
        array $rootValue,
        array $contextValue,
        ?array $variableValues,
        ?string $operationName,
        ?array $validationRules
    ): ExecutionResult 
    {
        return GraphQL::executeQuery(
            schema: $this->schema,
            source: $source,
            rootValue: $rootValue,
            contextValue: $contextValue,
            variableValues: $variableValues,
            operationName: $operationName,
            fieldResolver: $this->resolver,
            validationRules: $validationRules
        );
    }
}