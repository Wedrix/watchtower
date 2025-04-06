<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema as GraphQLSchema;
use GraphQL\Type\Definition\ResolveInfo;

final class Schema extends GraphQLSchema
{
    private readonly GraphQLSchema $schema;

    public function __construct(
        string $sourceFile, 
        ScalarTypeDefinitions $scalarTypeDefinitions,
        string $cacheDirectory,
        bool $optimize
    )
    {
        /**
         * @var array<string,GraphQLSchema>
         */
        static $schemas = [];

        static $cacheFile = null;

        if (!\is_file($sourceFile)) {
            throw new \Exception("The schema '{$sourceFile}' does not exist. Kindly generate it first to proceed.");
        }

        $cacheFile ??= $cacheDirectory.'/'.\pathinfo($sourceFile,\PATHINFO_BASENAME);

        if ($optimize) {
            if (!\is_file($cacheFile)) {
                throw new \Exception("The cache '{$cacheFile}' does not exist. Kindly generate it first to proceed.");
            }
        }

        $this->schema = $schemas[$sourceFile] ??= (function () use ($sourceFile, $scalarTypeDefinitions, $cacheFile, $optimize): GraphQLSchema {
            /**
             * @param array<string,mixed> $typeConfig
             * 
             * @return array<string,mixed>
             */
            $typeConfigDecorator = function (array $typeConfig, TypeDefinitionNode $typeDefinitionNode) use($scalarTypeDefinitions): array {
                $astNode = $typeConfig['astNode'] ?? null;
    
                if ($astNode instanceof ScalarTypeDefinitionNode) {
                    $scalarTypeDefinition = GenericScalarTypeDefinition(
                        typeName: $typeName = $typeConfig['name']
                    );
    
                    if (!$scalarTypeDefinitions->contains($scalarTypeDefinition)) {
                        throw new \LogicException("The type definition for '$typeName' does not exist.
                            Kindly create it in '{$scalarTypeDefinitions->filePath($scalarTypeDefinition)}'.");
                    }

                    require_once $scalarTypeDefinitions->filePath($scalarTypeDefinition);
    
                    $typeConfig = \array_merge($typeConfig, [
                        'serialize' => $scalarTypeDefinition->namespace().'\\serialize',
                        'parseValue' => $scalarTypeDefinition->namespace().'\\parseValue',
                        'parseLiteral' => $scalarTypeDefinition->namespace().'\\parseLiteral',
                    ]);
                }

                if ($astNode instanceof InterfaceTypeDefinitionNode || $astNode instanceof UnionTypeDefinitionNode) {
                    $typeConfig = \array_merge($typeConfig, [
                        'resolveType' => function (array $value, array $context, ResolveInfo $resolveInfo): string {
                            $typeName = $value['__typename'] 
                                ?? throw new \Exception('Invalid abstract type. Kindly specify \'__typename\' in the resolved result.');

                            if (!\is_string($typeName)) {
                                throw new \Exception('Inalid typename type. \'__typename\' must be a string.');
                            }

                            return $typeName;
                        }
                    ]);
                }
            
                return $typeConfig;
            };
    
            $AST = (function () use($optimize, $sourceFile, $cacheFile): DocumentNode {
                if ($optimize) {
                    $document = AST::fromArray(require $cacheFile);

                    if (!$document instanceof DocumentNode) {
                        throw new \Exception('Invalid schema. Could not be parsed as a document node.');
                    }

                    return $document;
                }
                
                return Parser::parse(
                    source: \is_string($schemaFileContents = \file_get_contents($sourceFile)) 
                                ? $schemaFileContents 
                                : throw new \Exception("Unable to read the schema file '{$sourceFile}'.")
                );
            })();
            
            return BuildSchema::build($AST, $typeConfigDecorator);
        })();
    }

    /**
     * Magic method to handle calls to undefined methods.
     * If the method exists on the GraphQL Schema instance, it proxies the call to it.
     *
     * @param string $name The name of the method being called.
     * @param array<int,mixed> $arguments The arguments passed to the method.
     *
     * @return mixed The result of the proxied method call.
     *
     * @throws \BadMethodCallException If the method does not exist on the GraphQL Schema instance.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!\method_exists($this->schema, $name)) {
            throw new \BadMethodCallException("Method '{$name}' does not exist on the schema.");
        }

        return $this->schema->{$name}(...$arguments);
    }
}