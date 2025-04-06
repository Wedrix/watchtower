<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema as GraphQLTypeSchema;
use GraphQL\Validator\Rules\ValidationRule;

interface Executor
{
    /**
     * Executes a GraphQL query.
     *
     * @param string|DocumentNode $source The GraphQL query string or DocumentNode.
     * @param array<string,mixed> $rootValue The root value for the query.
     * @param array<string,mixed> $contextValue The context value for the query.
     * @param array<mixed>|null $variableValues The variable values for the query.
     * @param string|null $operationName The name of the operation to execute.
     * @param array<ValidationRule>|null $validationRules The validation rules to apply.
     */
    public function executeQuery(
        string|DocumentNode $source,
        array $rootValue,
        array $contextValue,
        ?array $variableValues,
        ?string $operationName,
        ?array $validationRules
    ): ExecutionResult;
}

/**
 * @api
 * 
 * @param EntityManagerInterface $entityManager The Doctrine entityManager instance.
 * @param string $schemaFile The schema file.
 * @param string $pluginsDirectory The plugin functions' directory.
 * @param string $scalarTypeDefinitionsDirectory The scalar types' definitions' directory.
 * @param bool $optimize Use the cache for improved perfomance. 
 *      Note: You must run Console::generateCache() to create the cache with the latest changes.
 * @param string $cacheDirectory The directory for storing cache files.
 */
function Executor(
    EntityManagerInterface $entityManager,
    string $schemaFile,
    string $pluginsDirectory,
    string $scalarTypeDefinitionsDirectory,
    string $cacheDirectory,
    bool $optimize
): Executor
{
    /**
     * @var \WeakMap<EntityManagerInterface,array<string,array<string,array<string,array<string,array<string,Executor>>>>
     */
    static $instances = new \WeakMap();

    return $instances[$entityManager][$schemaFile][$pluginsDirectory][$scalarTypeDefinitionsDirectory][$cacheDirectory][$optimize ? 'true' : 'false'] ??= new class(
        entityManager: $entityManager,
        schemaFile: $schemaFile,
        pluginsDirectory: $pluginsDirectory,
        scalarTypeDefinitionsDirectory: $scalarTypeDefinitionsDirectory,
        cacheDirectory: $cacheDirectory,
        optimize: $optimize
    ) implements Executor {
        private readonly GraphQLTypeSchema $schema;
    
        private readonly Resolver $resolver;

        public function __construct(
            private readonly EntityManagerInterface $entityManager,
            private readonly string $schemaFile,
            private readonly string $pluginsDirectory,
            private readonly string $scalarTypeDefinitionsDirectory,
            private readonly string $cacheDirectory,
            private readonly bool $optimize
        )
        {
            if (!\is_file($this->schemaFile)) {
                throw new \Exception("The schema '{$this->schemaFile}' does not exist. Kindly generate it first to proceed.");
            }
    
            $this->schema = new Schema(
                sourceFile: $this->schemaFile,
                scalarTypeDefinitions: ScalarTypeDefinitions(
                    directory: $this->scalarTypeDefinitionsDirectory,
                    cacheDirectory: $this->cacheDirectory,
                    optimize: $this->optimize
                ),
                cacheDirectory: $this->cacheDirectory,
                optimize: $this->optimize
            );
    
            $this->resolver = Resolver(
                entityManager: $this->entityManager,
                plugins: Plugins(
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
    };
}
