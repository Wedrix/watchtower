<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

use function Wedrix\Watchtower\ResolverPlugin;
use function Wedrix\Watchtower\SelectorPlugin;

trait BaseQuery
{
    private bool $isWorkable;

    private QueryBuilder $queryBuilder;

    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private Plugins $plugins
    )
    {
        $this->isWorkable = !$this->node->isAbstract()
            && $this->entityManager->hasEntity(name: $this->node->unwrappedType());

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->entityManager->createQueryBuilder();

            if ($this->isWorkable) {
                $rootEntity = $this->entityManager->findEntity(name: $this->node->unwrappedType());

                $queryBuilder->from(
                    from: $rootEntity->class(),
                    alias: '__root'
                );

                /**
                 * @var array<string>
                 */
                $selectedFields = (function() use ($rootEntity): array {
                    $fieldsSelection = $this->node->concreteFieldsSelection();
            
                    $requestedFields = \array_keys($fieldsSelection);
            
                    $selectedEntityFields = \array_filter(
                        $rootEntity->fieldNames(), 
                        static fn (string $entityField) => \in_array($entityField, $rootEntity->idFieldNames())
                            || \in_array($entityField, $requestedFields)
                            || \array_reduce(
                                $requestedFields, 
                                static function(bool $isRequestedEmbeddedField, string $requestedField) use ($entityField, $fieldsSelection) {
                                    /**
                                     * @var array<string,mixed>
                                     */
                                    $subFieldsSelection = $fieldsSelection[$requestedField]['fields'] ?? [];
            
                                    if (!empty($subFieldsSelection)) {
                                        $requestedSubFields = \array_keys($subFieldsSelection);
            
                                        $requestedEmbeddedFields = \array_map(
                                            static fn (string $requestedSubField) => "$requestedField.$requestedSubField", 
                                            $requestedSubFields
                                        );
            
                                        return $isRequestedEmbeddedField || \in_array($entityField, $requestedEmbeddedFields);
                                    }
            
                                    return $isRequestedEmbeddedField;
                                }, 
                                false
                            )
                    );
            
                    $otherSelectedFields = \array_filter(
                        $requestedFields, 
                        static fn (string $requestedField) => !\in_array($requestedField, $rootEntity->associationNames()) 
                            && !\in_array($requestedField, $rootEntity->fieldNames())
                            && !\array_reduce(
                                $rootEntity->fieldNames(), 
                                static fn (bool $isEmbeddedEntityField, string $entityField) => $isEmbeddedEntityField || \str_starts_with($entityField, "$requestedField."), 
                                false
                            )
                    );

                    $otherSelectedFieldsWithoutResolvedFields = \array_filter(
                        $otherSelectedFields,
                        fn (string $otherSelectedField) => !$this->plugins->contains(
                            ResolverPlugin(
                                nodeType: $this->node->unwrappedType(),
                                fieldName: $otherSelectedField
                            )
                        )
                    );
                    
                    return \array_merge($selectedEntityFields, $otherSelectedFieldsWithoutResolvedFields);
                })();
        
                foreach ($selectedFields as $fieldName) {
                    $selectorPlugin = SelectorPlugin(
                        nodeType: $this->node->unwrappedType(),
                        fieldName: $fieldName
                    );
        
                    if ($this->plugins->contains($selectorPlugin)) {
                        require_once $this->plugins->filePath($selectorPlugin);

                        $selectorPlugin->callback()($queryBuilder, $this->node);
                    }
                    else {
                        $queryBuilder->addSelect("{$queryBuilder->rootAlias()}.$fieldName");
                    }
                }

                $identifierAssociationFields = \array_filter(
                    $rootEntity->idFieldNames(),
                    static fn (string $idField) => \in_array($idField, $rootEntity->associationNames())
                );

                foreach ($identifierAssociationFields as $identifierAssociationField) {
                    $queryBuilder->addSelect("IDENTITY({$queryBuilder->rootAlias()}.$identifierAssociationField) AS __associated_$identifierAssociationField");
                }
            }
    
            return $queryBuilder;
        })();
    }

    public function builder(): QueryBuilder
    {
        return $this->queryBuilder;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}