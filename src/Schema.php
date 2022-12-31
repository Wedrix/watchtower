<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Utils\AST;
use GraphQL\Utils\BuildSchema;
use GraphQL\Language\AST\SchemaDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema as SchemaType;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ImplementingType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\InterfaceImplementations;
use Wedrix\Watchtower\ScalarTypeDefinition\GenericScalarTypeDefinition;

final class Schema extends SchemaType
{
    /**
     * @var array<string,SchemaType>
     */
    private static array $_schemas = [];

    private readonly SchemaType $schema;

    public function __construct(
        private readonly string $sourceFileDirectory, 
        private readonly string $cacheFileDirectory,
        private readonly bool $isCached,
        private readonly ScalarTypeDefinitions $scalarTypeDefinitions
    )
    {
        static::$_schemas[$sourceFileDirectory] ??= (function (): SchemaType {
            /**
             * @param array<string,mixed> $typeConfig
             * 
             * @return array<string,mixed>
             */
            $typeConfigDecorator = function (array $typeConfig, TypeDefinitionNode $typeDefinitionNode): array {
                $astNode = $typeConfig['astNode'] ?? null;
    
                if ($astNode instanceof ScalarTypeDefinitionNode) {
                    $scalarTypeDefinition = new GenericScalarTypeDefinition(
                        typeName: $typeName = $typeConfig['name']
                    );
    
                    if (!$this->scalarTypeDefinitions->contains($scalarTypeDefinition)) {
                        throw new \LogicException("The type definition for '$typeName' does not exist.
                            Kindly create it in '{$this->scalarTypeDefinitions->directory($scalarTypeDefinition)}'.");
                    }

                    require_once $this->scalarTypeDefinitions->directory($scalarTypeDefinition);
    
                    $typeConfig = array_merge($typeConfig, [
                        'serialize' => $scalarTypeDefinition->namespace()."\\serialize",
                        'parseValue' => $scalarTypeDefinition->namespace()."\\parseValue",
                        'parseLiteral' => $scalarTypeDefinition->namespace()."\\parseLiteral",
                    ]);
                }

                if ($astNode instanceof InterfaceTypeDefinitionNode || $astNode instanceof UnionTypeDefinitionNode) {
                    $typeConfig = array_merge($typeConfig, [
                        'resolveType' => function (array $value, array $context, ResolveInfo $resolveInfo): string {
                            $typeName = $value['__typename'] 
                                ?? throw new \Exception("Invalid abstract type. Kindly specify '__typename' in the resolved result.");

                            if (!is_string($typeName)) {
                                throw new \Exception("Inalid typename type. '__typename' must be a string.");
                            }

                            return $typeName;
                        }
                    ]);
                }
            
                return $typeConfig;
            };
    
            $AST = (function (): DocumentNode {
                $document = Parser::parse(
                    source: is_string($schemaFileContents = file_get_contents($this->sourceFileDirectory)) 
                                ? $schemaFileContents 
                                : throw new \Exception("Unable to read GraphQL schema file.")
                );

                if ($this->isCached) {
                    if (!file_exists($this->cacheFileDirectory)) {
                        $dirname = pathinfo($this->cacheFileDirectory)['dirname'] ?? '';
        
                        if (!is_dir($dirname)) {
                            mkdir(directory: $dirname, recursive: true);
                        }
        
                        file_put_contents($this->cacheFileDirectory, "<?php\nreturn " . var_export(AST::toArray($document), true) . ";\n");
    
                        return $document;
                    }

                    $document = AST::fromArray(require $this->cacheFileDirectory);

                    if (!$document instanceof DocumentNode) {
                        throw new \Exception("Invalid schema. Could not be parsed as a document node.");
                    }
                }

                return $document;
            })();
            
            return BuildSchema::build($AST, $typeConfigDecorator);
        })();

        $this->schema = static::$_schemas[$sourceFileDirectory];
    }

    public function getTypeMap(): array
    {
        return $this->schema
                    ->getTypeMap();
    }

    public function getDirectives()
    {
        return $this->schema
                    ->getDirectives();
    }

    public function getOperationType(
        $operation
    )
    {
        return $this->schema
                    ->getOperationType($operation);
    }

    public function getQueryType(): ?Type
    {
        return $this->schema
                    ->getQueryType();
    }

    public function getMutationType(): ?Type
    {
        return $this->schema
                    ->getMutationType();
    }

    public function getSubscriptionType(): ?Type
    {
        return $this->schema
                    ->getSubscriptionType();
    }

    public function getConfig()
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
        Type $abstractType
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

    /**
     * @inheritdoc
     */
    public function isPossibleType(
        AbstractType $abstractType, 
        ObjectType $possibleType
    ): bool
    {
        return $this->schema
                    ->isPossibleType($abstractType, $possibleType);
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
                    ->getAstNode();
    }

    public function assertValid(): void
    {
        $this->schema
            ->assertValid();
    }

    public function validate()
    {
        return $this->schema
                    ->validate();
    }
}