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
     * @param string $schemaFileDirectory The schema file's directory.
     * @param string $schemaCacheFileDirectory The schema's generated cache file's directory.
     * @param bool $cachesTheSchema Whether this executor caches the schema for improved perfomance.
     *       Note that you will have to run Console::updateSchema() to reflect any changes.
     * @param string $pluginsDirectory The plugin functions' directory.
     * @param string $scalarTypeDefinitionsDirectory The scalar types' definitions' directory.
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $schemaFileDirectory,
        private readonly string $schemaCacheFileDirectory,
        private readonly bool $cachesTheSchema,
        private readonly string $pluginsDirectory,
        private readonly string $scalarTypeDefinitionsDirectory
    )
    {
        $this->schema = (function (): GraphQLTypeSchema {
            if (!file_exists($this->schemaFileDirectory)) {
                throw new \Exception("Invalid schema file directory. The file does not exist.");
            }

            return new Schema(
                sourceFileDirectory: $this->schemaFileDirectory,
                cacheFileDirectory: $this->schemaCacheFileDirectory,
                isCached: $this->cachesTheSchema,
                scalarTypeDefinitions: new ScalarTypeDefinitions(
                    directory: $this->scalarTypeDefinitionsDirectory
                )
            );
        })();

        $this->resolver = (function (): Resolver {
            return new Resolver(
                entityManager: $this->entityManager,
                plugins: new Plugins(
                    directory: $this->pluginsDirectory
                )
            );
        })();
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