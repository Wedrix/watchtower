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
             * @var array<string,mixed>
             */
            $types = [];

            $createEntityType = function (Entity $entity) use (&$types, &$createEntityType): void {
                $types[$entity->name()] ??= new ObjectType([
                    'name' => $entity->name(),
                    'fields' => (function () use ($entity, &$types, &$createEntityType): array {
                        /**
                         * @var array<string,array>
                         */
                        $fields = [];

                        /**
                         * @var array<string,array>
                         */
                        $entityFields = [];

                        foreach ($entity->fields() as $field) {
                            if (str_contains($field, '.')) {
                                [$fieldName, $embeddedFieldName] = explode('.', $field);

                                if (isset($entityFields[$fieldName])) {
                                    $entityFields[$fieldName]['embeds'][$embeddedFieldName] = [
                                        'mapping' => $entity->fieldMapping($field)
                                    ];
                                }
                                else {
                                    $entityFields[$fieldName] = [
                                        'embeds' => [
                                            $embeddedFieldName => [
                                                'mapping' => $entity->fieldMapping($field)
                                            ]
                                        ]
                                    ];
                                }
                            }
                            else {
                                $entityFields[$field] = [
                                    'mapping' => $entity->fieldMapping($field)
                                ];
                            }
                        }

                        $getTypeFromFieldInfo = function (array $fieldInfo): Type {
                            if (in_array($fieldInfo['mapping']['type'], ['smallint','integer','bigint'])) {
                                return Type::int();
                            }

                            if (in_array($fieldInfo['mapping']['type'], ['decimal','float'])) {
                                return Type::float();
                            }

                            if (in_array($fieldInfo['mapping']['type'], ['boolean'])) {
                                return Type::boolean();
                            }

                            return Type::string();
                        };

                        foreach ($entityFields as $fieldName => $fieldInfo) {
                            if (isset($fieldInfo['embeds'])) {
                                $embeddedClass = $entity->embeddedFieldClass($fieldName);

                                $embeddedTypeName = ($nameElements = explode("\\", $embeddedClass))[count($nameElements) - 1];

                                $types[$embeddedTypeName] ??= new ObjectType([
                                    'name' => $embeddedTypeName,
                                    'fields' => (function () use ($fieldInfo, &$getTypeFromFieldInfo): array {
                                        $embedFields = [];

                                        $embeds = $fieldInfo['embeds'];

                                        foreach ($embeds as $embedFieldName => $embedFieldInfo) {
                                            $embedFields[$embedFieldName] = $getTypeFromFieldInfo($embedFieldInfo);
                                        }

                                        return $embedFields;
                                    })()
                                ]);

                                $fields[$fieldName] = $types[$embeddedTypeName];
                            }
                            
                            if (isset($fieldInfo['mapping'])) {
                                $fields[$fieldName] = $getTypeFromFieldInfo($fieldInfo);
                            }
                        }

                        foreach ($entity->associations() as $associationName) {
                            $associationMapping = $entity->associationMapping($associationName);
        
                            $associatedEntityName = ($nameElements = explode("\\",$associationMapping['targetEntity']))[count($nameElements) - 1];
        
                            $associatedEntity = new Entity(
                                name: $associatedEntityName,
                                entityManager: $this->entityManager
                            );
        
                            $types[$associatedEntityName] ??= $createEntityType($associatedEntity);
        
                            $feilds[$associationName] = $types[$associatedEntityName];
        
                        }

                        return $fields;
                    })()
                ]);
            };
    
            foreach ($entityClassNames as $entityClassName) {
                $entityName = ($nameElements = explode("\\", $entityClassName))[count($nameElements) - 1];

                $entity = new Entity(
                    name: $entityName,
                    entityManager: $this->entityManager
                );

                $createEntityType($entity);
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
                        'limit' => Type::nonNull(Type::int()),
                        'page' => Type::nonNull(Type::int()),
                    ]
                ]);
    
                $queries[$collectionQueryName] = [
                    'type' => Type::nonNull(Type::listOf($type)),
                    'args' => [
                        'queryParams' => [
                            'type' => Type::nonNull($types[$queryParamsTypeName])
                        ],
                        'distinct' => [
                            'type' => Type::boolean()
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