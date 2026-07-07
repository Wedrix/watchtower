<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

/**
 * Builds sub-level relation queries by matching each child row back to parent identifiers.
 *
 * Resolution strategy matrix:
 * - Parent association is inverse side (`mappedBy` exists):
 *   - Target owning field is to-one: compare `IDENTITY(__root.mappedBy, parentId)` per parent id.
 *   - Target owning field is to-many: `JOIN __root.mappedBy __parent` and filter `__parent` ids.
 * - Parent association is owning side:
 *   - `inversedBy` exists (bidirectional owning): `JOIN __root.inversedBy __parent` and filter `__parent` ids.
 *   - `inversedBy` missing (unidirectional owning):
 *     - To-one (`OneToOne`/`ManyToOne`): add `FROM parent __parent` and constrain `__parent.association = __root`.
 *     - To-many (`ManyToMany`): add `FROM parent __parent` and constrain `__root MEMBER OF __parent.association`.
 * - The field declares `@watchtowerAssociation(through: "...")`: join the returned root entity
 *   to the through entity, then join the through entity back to `__parent`.
 *
 * In all paths, parent id columns are selected as `__parent_*` aliases so `QueryResult`
 * can split batched child results back to the originating parent node.
 */
trait ParentAssociatedQuery
{
    public function __construct(
        private Node $node,
        private EntityManager $entityManager,
        private QueryBuilder $queryBuilder,
        private bool $isWorkable
    ) {
        $this->isWorkable = $this->isWorkable
            && ! $this->node->isTopLevel()
            && $this->entityManager->hasEntity(name: $this->node->unwrappedParentType());
    }

    public function builder(): QueryBuilder
    {
        if ($this->isWorkable) {
            $batchedNodes = (function (): array {
                $batchKey = BatchKey(node: $this->node);

                $nodes = [$this->node];

                foreach (NodeBuffer() as $bufferedNode) {
                    $_batchKey = BatchKey(node: $bufferedNode);

                    if ($_batchKey->value() === $batchKey->value()) {
                        $nodes[] = $bufferedNode;
                    }
                }

                return $nodes;
            })();

            $parentEntity = $this->entityManager->findEntity(name: $this->node->unwrappedParentType());

            $parentIds = \array_map(
                fn (Node $batchedNode): array => $batchedNode->parentId(),
                $batchedNodes
            );

            $association = $this->node->name();

            $rootAlias = $this->queryBuilder->rootAlias();

            $parentAlias = $this->queryBuilder->parentAlias();

            $constrainByParentAlias = function () use ($parentEntity, $parentAlias, $parentIds): void {
                $idFieldNames = $parentEntity->idFieldNames();

                // Build OR conditions for composite key matching
                $orConditions = $this->queryBuilder->expr()->orX();

                foreach ($parentIds as $index => $parentId) {
                    $andConditions = $this->queryBuilder->expr()->andX();

                    foreach ($idFieldNames as $idFieldName) {
                        $parentIdParameter = "{$parentAlias}_{$idFieldName}_{$index}";
                        $andConditions->add(
                            $this->queryBuilder->expr()
                                ->eq("$parentAlias.$idFieldName", ":$parentIdParameter")
                        );
                        $this->queryBuilder->setParameter($parentIdParameter, $parentId[$idFieldName]);
                    }

                    $orConditions->add($andConditions);
                }

                $this->queryBuilder->andWhere($orConditions);

                foreach ($idFieldNames as $idFieldName) {
                    if (\in_array($idFieldName, $parentEntity->associationFieldNames())) {
                        $targetEntity = $this->entityManager->findEntity(
                            name: $parentEntity->associationTargetEntity(
                                associationName: $idFieldName
                            )
                        );

                        foreach ($targetEntity->idFieldNames() as $targetIdFieldName) {
                            $parentIdResultAlias = "{$parentAlias}_{$idFieldName}_{$targetIdFieldName}";
                            $this->queryBuilder->addSelect("IDENTITY($parentAlias.$idFieldName, '$targetIdFieldName') AS $parentIdResultAlias");
                        }

                        continue;
                    }

                    $parentIdResultAlias = "{$parentAlias}_$idFieldName";
                    $this->queryBuilder->addSelect("$parentAlias.$idFieldName AS $parentIdResultAlias");
                }
            };

            $associationDirective = $this->node->associationDirective();
            $throughAssociation = $associationDirective['through'] ?? null;

            if (! empty($associationDirective)) {
                $rootEntity = $this->entityManager->findEntity(name: $this->node->unwrappedType());
                $fieldName = $this->node->unwrappedParentType().'.'.$this->node->name();

                if (! \is_string($throughAssociation) || $throughAssociation === '') {
                    throw new InvalidAssociationConfigurationParentAssociatedQueryException("Invalid @watchtowerAssociation configuration for '{$fieldName}'. The 'through' argument must be a non-empty string.");
                }

                if (! \in_array($throughAssociation, $rootEntity->associationFieldNames(), true)) {
                    throw new InvalidAssociationConfigurationParentAssociatedQueryException("Invalid @watchtowerAssociation configuration for '{$fieldName}'. The returned entity '{$rootEntity->name()}' does not define an association named '{$throughAssociation}'.");
                }

                $throughEntity = $this->entityManager->findEntity(
                    name: $rootEntity->associationTargetEntity($throughAssociation)
                );

                $parentAssociationNames = \array_values(
                    \array_filter(
                        $throughEntity->associationFieldNames(),
                        fn (string $throughEntityAssociationName): bool => $throughEntity->associationTargetEntity($throughEntityAssociationName) === $parentEntity->name()
                    )
                );

                if (\count($parentAssociationNames) !== 1) {
                    $matches = \count($parentAssociationNames) === 0
                        ? 'none'
                        : \implode(', ', $parentAssociationNames);

                    throw new InvalidAssociationConfigurationParentAssociatedQueryException("Invalid @watchtowerAssociation configuration for '{$fieldName}'. The through entity '{$throughEntity->name()}' must define exactly one association to parent entity '{$parentEntity->name()}'; found {$matches}.");
                }

                $throughJoin = "$rootAlias.$throughAssociation";
                $throughEntityAlias = $this->queryBuilder->joinAlias(
                    $throughJoin,
                    'watchtowerThrough'
                );
                $parentAssociation = $parentAssociationNames[0];

                $this->queryBuilder->joinOnce(
                    $throughJoin,
                    $throughEntityAlias
                );

                $this->queryBuilder->joinOnce(
                    "$throughEntityAlias.$parentAssociation",
                    $parentAlias
                );

                $constrainByParentAlias();

                return $this->queryBuilder;
            }

            if ($parentEntity->associationIsInverseSide($association)) {
                $mappedByAssociation = $parentEntity->associationMappedByTargetField($association);
                $rootEntity = $this->entityManager->findEntity(name: $this->node->unwrappedType());

                $idFieldNames = $parentEntity->idFieldNames();

                if ($rootEntity->associationIsSingleValued($mappedByAssociation)) {
                    // Build OR conditions for composite key matching
                    $orConditions = $this->queryBuilder->expr()->orX();

                    foreach ($parentIds as $index => $parentId) {
                        $andConditions = $this->queryBuilder->expr()->andX();

                        foreach ($idFieldNames as $idFieldName) {
                            $parentIdParameter = "{$parentAlias}_{$idFieldName}_{$index}";
                            $andConditions->add(
                                $this->queryBuilder->expr()
                                    ->eq("IDENTITY($rootAlias.$mappedByAssociation, '$idFieldName')", ":$parentIdParameter")
                            );
                            $this->queryBuilder->setParameter($parentIdParameter, $parentId[$idFieldName]);
                        }

                        $orConditions->add($andConditions);
                    }

                    $this->queryBuilder->andWhere($orConditions);

                    foreach ($idFieldNames as $idFieldName) {
                        $parentIdResultAlias = "{$parentAlias}_$idFieldName";
                        $this->queryBuilder->addSelect("IDENTITY($rootAlias.$mappedByAssociation, '$idFieldName') AS $parentIdResultAlias");
                    }
                } else {
                    $this->queryBuilder->joinOnce(
                        "$rootAlias.$mappedByAssociation",
                        $parentAlias
                    );

                    $constrainByParentAlias();
                }
            } else {
                $inversedByAssociation = $parentEntity->associationInversedByTargetField($association);

                if ($inversedByAssociation !== null) {
                    $this->queryBuilder->joinOnce(
                        "$rootAlias.$inversedByAssociation",
                        $parentAlias
                    );
                } else {
                    $this->queryBuilder->from(
                        $parentEntity->class(),
                        $parentAlias
                    );

                    if ($parentEntity->associationIsSingleValued($association)) {
                        $this->queryBuilder->andWhere(
                            $this->queryBuilder->expr()
                                ->eq("$parentAlias.$association", $rootAlias)
                        );
                    } else {
                        $this->queryBuilder->andWhere(
                            $this->queryBuilder->expr()
                                ->isMemberOf($rootAlias, "$parentAlias.$association")
                        );
                    }
                }

                $constrainByParentAlias();
            }
        }

        return $this->queryBuilder;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}
