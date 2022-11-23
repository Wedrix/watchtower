<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Language\AST\SchemaDefinitionNode;
use GraphQL\Type\Schema as SchemaType;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ImplementingType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\InterfaceImplementations;

use function Wedrix\Watchtower\string\camelize;
use function Wedrix\Watchtower\string\pluralize;

final class SyncedQuerySchema extends SchemaType
{
    private readonly SchemaType $schema;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    )
    {
        $this->schema = (function (): SchemaType {
            $entityClassNames = $this->entityManager->getConfiguration()->getMetadataDriverImpl()?->getAllClassNames() 
                    ?? throw new \Exception("Invalid EntityManager. The metadata driver implementation is not set.");
    
            /**
             * @var array<string,NullableType>
             */
            $types = [];

            $addEntityType = function (Entity $entity) use (&$types): void {
                $types[$entity->name()] ??= new ObjectType([
                    'name' => $entity->name(),
                    'fields' => (function () use ($entity, &$types): array {
                        /**
                         * @var array<string,Type>
                         */
                        $fields = [];

                        /**
                         * @var array<string,string|array<string,mixed>>
                         */
                        $entityFields = [];

                        foreach ($entity->fields() as $field) {
                            if (str_contains($field, '.')) {
                                [$fieldName, $embeddedFieldName] = explode('.', $field);

                                if (isset($entityFields[$fieldName])) {
                                    $entityFields[$fieldName]['embedded_fields'][$embeddedFieldName] = $entity->fieldType($field);
                                }
                                else {
                                    $entityFields[$fieldName] = [
                                        'embedded_fields' => [
                                            $embeddedFieldName => $entity->fieldType($field)
                                        ]
                                    ];
                                }
                            }
                            else {
                                $entityFields[$field] = $entity->fieldType($field);
                            }
                        }

                        $mapScalarType = function (string $scalarType): Type {
                            if (in_array($scalarType, ['smallint','integer','bigint'])) {
                                return Type::int();
                            }

                            if (in_array($scalarType, ['decimal','float'])) {
                                return Type::float();
                            }

                            if (in_array($scalarType, ['boolean'])) {
                                return Type::boolean();
                            }

                            return Type::string();
                        };

                        foreach ($entityFields as $fieldName => $fieldTypeOrInfo) {
                            if (is_array($fieldTypeOrInfo) && isset($fieldTypeOrInfo['embedded_fields'])) {
                                $embeddedClass = $entity->embeddedFieldClass($fieldName);

                                $embeddedTypeName = ($nameElements = explode("\\", $embeddedClass))[count($nameElements) - 1];

                                $types[$embeddedTypeName] ??= new ObjectType([
                                    'name' => $embeddedTypeName,
                                    'fields' => (function () use ($entity, $fieldName, $fieldTypeOrInfo, &$mapScalarType): array {
                                        $embeddedFields = [];

                                        $embeddedFieldTypes = $fieldTypeOrInfo['embedded_fields'];

                                        foreach ($embeddedFieldTypes as $embeddedFieldName => $embeddedFieldType) {
                                            $fieldType = $mapScalarType($embeddedFieldType);

                                            if (!$entity->fieldIsNullable("$fieldName.$embeddedFieldName")) {
                                                $fieldType = Type::nonNull($fieldType);
                                            }

                                            $embeddedFields[$embeddedFieldName] = $fieldType;
                                        }

                                        return $embeddedFields;
                                    })()
                                ]);

                                $fields[$fieldName] = $types[$embeddedTypeName];
                            }
                            
                            if (is_string($fieldTypeOrInfo)){
                                $fieldType = $mapScalarType($fieldTypeOrInfo);

                                if (!$entity->fieldIsNullable($fieldName)) {
                                    $fieldType = Type::nonNull($fieldType);
                                }

                                $fields[$fieldName] = $fieldType;
                            }
                        }

                        foreach ($entity->associations() as $associationName) {
                            $associatedEntityName = ($nameElements = explode("\\",$entity->associationTargetEntity($associationName)))[count($nameElements) - 1];

                            $associatedEntityType = function () use (&$types, $associatedEntityName): NullableType {
                                return $types[$associatedEntityName];
                            };

                            if (!$entity->associationIsSingleValued($associationName)) {
                                $associatedEntityType = Type::listOf(Type::nonNull($associatedEntityType));
                            }

                            if (!$entity->associationIsNullable($associationName)) {
                                $associatedEntityType = Type::nonNull($associatedEntityType);
                            }

                            $fields[$associationName] = $associatedEntityType;
                        }

                        return $fields;
                    })()
                ]);
            };
    
            foreach ($entityClassNames as $entityClassName) {
                $addEntityType(
                    new Entity(
                        name: ($nameElements = explode("\\", $entityClassName))[count($nameElements) - 1],
                        entityManager: $this->entityManager
                    )
                );
            }
 
            $queries = [];
    
            foreach ($entityClassNames as $entityClassName) {
                $singleQueryName = camelize($entityName = ($nameElements = explode("\\", $entityClassName))[count($nameElements) - 1]);

                $collectionQueryName = pluralize($singleQueryName);
    
                $queries[$singleQueryName] = [
                    'type' => $type = Type::nonNull($types[$entityName]),
                    'args' => [
                        'id' => [
                            'type' => Type::nonNull(Type::id())
                        ]
                    ]
                ];
    
                $types[$queryParamsTypeName = pluralize($entityName)."QueryParams"] = new InputObjectType([
                    'name' => $queryParamsTypeName,
                    'fields' => [
                        'limit' => Type::int(),
                        'page' => Type::int(),
                        'distinct' => Type::boolean()
                    ]
                ]);
    
                $queries[$collectionQueryName] = [
                    'type' => Type::nonNull(Type::listOf($type)),
                    'args' => [
                        'queryParams' => [
                            'type' => $types[$queryParamsTypeName]
                        ]
                    ]
                ];
            }
   
            return new SchemaType([
                'query' => new ObjectType([
                    'name' => 'Query',
                    'fields' => $queries
                ])
            ]);
        })();
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