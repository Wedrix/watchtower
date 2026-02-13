<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\SchemaDefinitionNode;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ImplementingType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema as GraphQLSchema;
use GraphQL\Type\SchemaConfig;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\InterfaceImplementations;

final class Schema extends GraphQLSchema
{
    private GraphQLSchema $schema;

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

        $this->schema = $schemas[$sourceFile] ??= (static function () use ($sourceFile, $scalarTypeDefinitions, $cacheFile, $optimize): GraphQLSchema {
            /**
             * @param array<string,mixed> $typeConfig
             * 
             * @return array<string,mixed>
             */
            $typeConfigDecorator = static function (array $typeConfig, TypeDefinitionNode $typeDefinitionNode) use ($scalarTypeDefinitions): array {
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
                        'resolveType' => static function (array $value, array $context, ResolveInfo $resolveInfo): string {
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
    
            $AST = (static function () use ($optimize, $sourceFile, $cacheFile): DocumentNode {
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

        parent::__construct($this->schema->getConfig());
    }

    public function getTypeMap(): array
    {
        return $this->schema
                    ->getTypeMap();
    }

    public function getDirectives(): array
    {
        return $this->schema
                    ->getDirectives();
    }

    public function getOperationType(
        string $operation
    ): ?ObjectType
    {
        return $this->schema
                    ->getOperationType($operation);
    }

    public function getQueryType(): ?ObjectType
    {
        return $this->schema
                    ->getQueryType();
    }

    public function getMutationType(): ?ObjectType
    {
        return $this->schema
                    ->getMutationType();
    }

    public function getSubscriptionType(): ?ObjectType
    {
        return $this->schema
                    ->getSubscriptionType();
    }

    public function getConfig(): SchemaConfig
    {
        return $this->schema
                    ->getConfig();
    }

    public function getType(
        string $name
    ): ?Type
    {
        return $this->schema
                    ->getType($name);
    }

    public function hasType(
        string $name
    ): bool
    {
        return $this->schema
                    ->hasType($name);
    }

    public function getPossibleTypes(
        AbstractType $abstractType
    ): array
    {
        return $this->schema
                    ->getPossibleTypes($abstractType);
    }

    public function getImplementations(
        InterfaceType $abstractType
    ): InterfaceImplementations
    {
        return $this->schema
                    ->getImplementations($abstractType);
    }

    public function isSubType(
        AbstractType $abstractType, 
        ImplementingType $maybeSubType
    ): bool
    {
        return $this->schema
                    ->isSubType($abstractType, $maybeSubType);
    }

    public function getDirective(
        string $name
    ): ?Directive
    {
        return $this->schema
                    ->getDirective($name);
    }

    public function getAstNode(): ?SchemaDefinitionNode
    {
        return $this->schema
                    ->getConfig()
                    ->getAstNode();
    }

    public function assertValid(): void
    {
        $this->schema
            ->assertValid();
    }

    public function validate(): array
    {
        return $this->schema
                    ->validate();
    }
}
