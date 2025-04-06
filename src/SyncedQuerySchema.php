<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\ORM\EntityManagerInterface;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Schema as GraphQLSchema;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\NullableType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

use function Wedrix\Watchtower\camelize;
use function Wedrix\Watchtower\pluralize;

final class SyncedQuerySchema extends GraphQLSchema
{
    private readonly GraphQLSchema $schema;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    )
    {
        $this->schema = (function(): GraphQLSchema {
            $scalars = [
                'DateTime' => new CustomScalarType([
                    'name' => 'DateTime'
                ]),
                'Limit' => new CustomScalarType([
                    'name' => 'Limit'
                ]),
                'Page' => new CustomScalarType([
                    'name' => 'Page'
                ])
            ];

            /**
             * @var array<string,NullableType>
             */
            $types = [];

            $addEntityType = function(Entity $entity) use (&$types, &$scalars, &$addEntityType): void {
                $types[$entity->name()] ??= new ObjectType([
                    'name' => $entity->name(),
                    'fields' => (function() use ($entity, &$types, &$scalars, &$addEntityType): array {
                        /**
                         * @var array<string,Type>
                         */
                        $fields = [];

                        /**
                         * @var array<string,string|array<string,mixed>>
                         */
                        $entityFields = [];

                        foreach ($entity->fields() as $field) {
                            if (\str_contains($field, '.')) {
                                [$fieldName, $embeddedFieldName] = \explode('.', $field);

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

                        $mapScalarType = static function(string $scalarType) use (&$scalars): ScalarType|null {
                            if (\in_array($scalarType, ['smallint','integer','bigint'])) {
                                return Type::int();
                            }

                            if (\in_array($scalarType, ['decimal','float'])) {
                                return Type::float();
                            }

                            if (\in_array($scalarType, ['boolean'])) {
                                return Type::boolean();
                            }

                            if (\in_array($scalarType, ['string','ascii_string','text','guid'])) {
                                return Type::string();
                            }

                            if (\in_array($scalarType, [
                                'date','date_immutable','datetime','datetime_immutable',
                                'datetimetz','datetimetz_immutable','time','time_immutable'
                            ])) {
                                return $scalars['DateTime'];
                            }

                            return null;
                        };

                        foreach ($entityFields as $fieldName => $fieldTypeOrInfo) {
                            if (\is_array($fieldTypeOrInfo) && isset($fieldTypeOrInfo['embedded_fields'])) {
                                $embeddedClass = $entity->embeddedFieldClass($fieldName);

                                $embeddedTypeName = \array_slice(\explode('\\', $embeddedClass), -1)[0];

                                //TODO: Handle Non-Nullable Embeddables
                                $types[$embeddedTypeName] ??= new ObjectType([
                                    'name' => $embeddedTypeName,
                                    'fields' => (static function() use ($entity, $fieldName, $fieldTypeOrInfo, &$mapScalarType): array {
                                        $embeddedFields = [];

                                        $embeddedFieldTypes = $fieldTypeOrInfo['embedded_fields'];

                                        foreach ($embeddedFieldTypes as $embeddedFieldName => $embeddedFieldType) {
                                            $fieldType = $mapScalarType($embeddedFieldType);

                                            if (!\is_null($fieldType)) {
                                                if (!$entity->fieldIsNullable("$fieldName.$embeddedFieldName")) {
                                                    $fieldType = Type::nonNull($fieldType);
                                                }
    
                                                $embeddedFields[$embeddedFieldName] = $fieldType;
                                            }
                                        }

                                        return $embeddedFields;
                                    })()
                                ]);

                                $fields[$fieldName] = $types[$embeddedTypeName];
                            }
                            
                            if (\is_string($fieldTypeOrInfo)){
                                $fieldType = $mapScalarType($fieldTypeOrInfo);

                                if (!\is_null($fieldType)) {
                                    if (!$entity->fieldIsNullable($fieldName)) {
                                        $fieldType = Type::nonNull($fieldType);
                                    }
    
                                    $fields[$fieldName] = $fieldType;
                                }
                            }
                        }

                        foreach ($entity->associations() as $associationName) {
                            $associatedEntityName = \array_slice(\explode('\\',$entity->associationTargetEntity($associationName)), -1)[0];

                            $associatedEntityType = function() use (&$types, $associatedEntityName, $addEntityType): NullableType {
                                if (!isset($types[$associatedEntityName])) {
                                    $addEntityType(
                                        Entity(
                                            name: $associatedEntityName,
                                            entityManager: $this->entityManager
                                        )
                                    );
                                }

                                return $types[$associatedEntityName];
                            };

                            if (!$entity->associationIsSingleValued($associationName)) {
                                $associatedEntityType = Type::listOf(Type::nonNull($associatedEntityType));
                            }

                            if (!$entity->associationIsNullable($associationName)) {
                                $associatedEntityType = Type::nonNull($associatedEntityType);
                            }

                            $fields[$associationName] = $entity->associationIsSingleValued($associationName) 
                                        ? $associatedEntityType
                                        : [
                                            'type' => $associatedEntityType,
                                            'args' => [
                                                'queryParams' => [
                                                    'type' => static function() use (&$types, $associatedEntityName): NullableType {
                                                        return $types[pluralize($associatedEntityName).'QueryParams'];
                                                    }
                                                ]
                                            ]
                                        ];
                        }

                        return $fields;
                    })()
                ]);
            };
            
            $entityClassNames = $this->entityManager->getConfiguration()->getMetadataDriverImpl()?->getAllClassNames() 
                    ?? throw new \Exception('Invalid EntityManager. The metadata driver implementation is not set.');

            foreach ($entityClassNames as $entityClassName) {
                $addEntityType(
                    Entity(
                        name: \array_slice(\explode('\\', $entityClassName), -1)[0],
                        entityManager: $this->entityManager
                    )
                );
            }

            $queries = [];
    
            foreach ($entityClassNames as $entityClassName) {
                $singleQueryName = camelize($entityName = \array_slice(\explode('\\', $entityClassName), -1)[0]);

                $collectionQueryName = pluralize($singleQueryName);

                $entity = Entity(
                    name: \array_slice(\explode('\\', $entityClassName), -1)[0],
                    entityManager: $this->entityManager
                );
    
                $queries[$singleQueryName] = [
                    'type' => $type = Type::nonNull($types[$entityName]),
                    'args' => \array_reduce(
                        $entity->idFields(),
                        static function(array $args, string $idField): array {
                            $args[$idField] = [
                                'type' => Type::nonNull(Type::id())
                            ];
    
                            return $args;
                        },
                        []
                    )
                ];
    
                $types[$queryParamsTypeName = pluralize($entityName).'QueryParams'] = new InputObjectType([
                    'name' => $queryParamsTypeName,
                    'fields' => [
                        'limit' => $scalars['Limit'],
                        'page' => $scalars['Page'],
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
   
            return new GraphQLSchema([
                'query' => new ObjectType([
                    'name' => 'Query',
                    'fields' => $queries
                ])
            ]);
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