<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugins\SelectorPlugin;

final class BaseQuery implements Query
{
    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly Node $node,
        private readonly EntityManager $entityManager,
        private readonly array $context,
        private readonly Plugins $plugins
    )
    {
        $this->isWorkable = (function (): bool {
            return !$this->node->isAbstractType()
                && $this->entityManager->hasEntity(name: $this->node->type());
        })();

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->entityManager->createQueryBuilder();

            if ($this->isWorkable) {
                $rootEntity = $this->entityManager->findEntity(name: $this->node->type());

                $queryBuilder->from(
                    from: $rootEntity->class(),
                    alias: $queryBuilder->reconciledAlias("__{$this->node->fieldName()}")
                );

                /**
                 * @var array<string>
                 */
                $selectedFields = (function () use ($rootEntity): array {
                    $fieldsSelection = $this->node->concreteFieldsSelection();
            
                    $requestedFields = array_keys($fieldsSelection);
            
                    $selectedEntityFields = array_filter(
                        $rootEntity->fields(), 
                        fn (string $entityField) => in_array($entityField, $rootEntity->fields())
                            || in_array($entityField, $requestedFields)
                            || array_reduce(
                                $requestedFields, 
                                function (bool $isRequestedEmbeddedField, string $requestedField) use ($entityField, $fieldsSelection) {
                                    /**
                                     * @var array<string,mixed>
                                     */
                                    $subFieldsSelection = $fieldsSelection[$requestedField]['fields'] ?? [];
            
                                    if (!empty($subFieldsSelection)) {
                                        $requestedSubFields = array_keys($subFieldsSelection);
            
                                        $requestedEmbeddedFields = array_map(
                                            fn (string $requestedSubField) => "$requestedField.$requestedSubField", 
                                            $requestedSubFields
                                        );
            
                                        return $isRequestedEmbeddedField || in_array($entityField, $requestedEmbeddedFields);
                                    }
            
                                    return $isRequestedEmbeddedField;
                                }, 
                                false
                            )
                    );
            
                    $otherSelectedFields = array_filter(
                        $requestedFields, 
                        fn (string $requestedField) => !in_array($requestedField, $rootEntity->associations()) 
                            && !in_array($requestedField, $rootEntity->fields())
                            && !array_reduce(
                                $rootEntity->fields(), 
                                fn (bool $isEmbeddedEntityField, string $entityField) 
                                    => $isEmbeddedEntityField || str_starts_with($entityField, "$requestedField."), 
                                false
                            )
                    );
                    
                    return array_merge($selectedEntityFields, $otherSelectedFields);
                })();
        
                foreach ($selectedFields as $fieldName) {
                    $selectorPlugin = new SelectorPlugin(
                        nodeType: $this->node->type(),
                        fieldName: $fieldName
                    );
        
                    if ($this->plugins->contains($selectorPlugin)) {
                        require_once $this->plugins->directory($selectorPlugin);

                        $selectorPlugin->callback()($queryBuilder, $this->node, $this->context);
                    }
                    else {
                        $queryBuilder->addSelect("{$queryBuilder->rootAlias()}.$fieldName");
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