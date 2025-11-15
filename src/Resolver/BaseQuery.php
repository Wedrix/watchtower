<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;

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

                $rootEntityAlias = $queryBuilder->rootEntityAlias();
                
                $nodeFieldsSelection = $this->node->concreteFieldsSelection();
        
                $selectedNodeFields = \array_keys($nodeFieldsSelection);

                $queryBuilder->from(
                    from: $rootEntity->class(),
                    alias: $rootEntityAlias
                );

                /**
                 * @var array<string>
                 */
                $selectedScalarEntityFields = \array_filter(
                    $rootEntity->scalarFieldNames(), 
                    static fn (string $selectedScalarEntityField) => \in_array($selectedScalarEntityField, $rootEntity->idFieldNames())
                        || \in_array($selectedScalarEntityField, $selectedNodeFields)
                        || \array_reduce(
                            $selectedNodeFields, 
                            static function(bool $isSelectedEmbeddedField, string $selectedNodeField) use ($selectedScalarEntityField, $nodeFieldsSelection) {
                                /**
                                 * @var array<string,mixed>
                                 */
                                $subFieldsSelection = $nodeFieldsSelection[$selectedNodeField]['fields'] ?? [];
        
                                if (!empty($subFieldsSelection)) {
                                    $requestedSubFields = \array_keys($subFieldsSelection);
        
                                    $selectedEmbeddedFields = \array_map(
                                        static fn (string $requestedSubField) => "$selectedNodeField.$requestedSubField", 
                                        $requestedSubFields
                                    );
        
                                    return $isSelectedEmbeddedField || \in_array($selectedScalarEntityField, $selectedEmbeddedFields);
                                }
        
                                return $isSelectedEmbeddedField;
                            }, 
                            false
                        )
                );

                /**
                 * @var array<string>
                 */
                $selectedSelectorFields = \array_filter(
                    $selectedNodeFields, 
                    fn (string $selectedNodeField) => $this->plugins->contains(
                        SelectorPlugin(
                            nodeType: $this->node->unwrappedType(),
                            fieldName: $selectedNodeField
                        )
                    )
                );
        
                foreach ($selectedScalarEntityFields as $fieldName) {
                    $queryBuilder->addSelect("{$rootEntityAlias}.$fieldName");
                }

                foreach ($selectedSelectorFields as $fieldName) {
                    $selectorPlugin = SelectorPlugin(
                        nodeType: $this->node->unwrappedType(),
                        fieldName: $fieldName
                    );
                    
                    require_once $this->plugins->filePath($selectorPlugin);

                    $selectorPlugin->callback()($queryBuilder, $this->node);
                }

                $identifierAlias = $queryBuilder->identifierAlias();

                $identifierAssociationFields = \array_filter(
                    $rootEntity->idFieldNames(),
                    static fn (string $idField) => \in_array($idField, $rootEntity->associationFieldNames())
                );

                foreach ($identifierAssociationFields as $identifierAssociationField) {
                    $targetEntity = $this->entityManager->findEntity(
                        name: $rootEntity->associationTargetEntity(
                            associationName: $identifierAssociationField
                        )
                    );

                    foreach ($targetEntity->idFieldNames() as $targetIdFieldName) {
                        $idNameAlias = "{$identifierAlias}_{$identifierAssociationField}_{$targetIdFieldName}";
                        $queryBuilder->addSelect("IDENTITY({$rootEntityAlias}.$identifierAssociationField, '$targetIdFieldName') AS $idNameAlias");
                    }
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