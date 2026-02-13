<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

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
            $batchNodes = (function (): array {
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
                fn (Node $batchNode): array => \array_reduce(
                    $parentEntity->idFieldNames(),
                    function (array $parentIdValue, string $idFieldName) use ($parentEntity, $batchNode): array {
                        if (\in_array($idFieldName, $parentEntity->associationFieldNames())) {
                            $identifierAssociationField = $idFieldName;

                            $targetEntity = $this->entityManager->findEntity(
                                name: $parentEntity->associationTargetEntity(
                                    associationName: $identifierAssociationField
                                )
                            );

                            $parentIdValue[$identifierAssociationField] = \array_reduce(
                                $targetEntity->idFieldNames(),
                                function (array $associatedIdValue, string $targetIdFieldName) use ($identifierAssociationField, $batchNode): array {
                                    $identifierAlias = $this->queryBuilder->identifierAlias();
                                    $rootKey = "{$identifierAlias}_{$identifierAssociationField}_{$targetIdFieldName}";
                                    $associatedIdValue[$targetIdFieldName] = $batchNode->root()[$rootKey];

                                    return $associatedIdValue;
                                },
                                []
                            );
                        } else {
                            $parentIdValue[$idFieldName] = $batchNode->root()[$idFieldName];
                        }

                        return $parentIdValue;
                    },
                    []
                ),
                $batchNodes
            );

            $association = $this->node->name();

            $rootEntityAlias = $this->queryBuilder->rootEntityAlias();

            $parentEntityAlias = $this->queryBuilder->parentEntityAlias();

            if ($parentEntity->associationIsInverseSide($association)) {
                $association = $parentEntity->associationMappedByTargetField($association);
                $rootEntity = $this->entityManager->findEntity(name: $this->node->unwrappedType());

                $idFieldNames = $parentEntity->idFieldNames();

                if ($rootEntity->associationIsSingleValued($association)) {
                    // Build OR conditions for composite key matching
                    $orConditions = $this->queryBuilder->expr()->orX();

                    foreach ($parentIds as $index => $parentId) {
                        $andConditions = $this->queryBuilder->expr()->andX();

                        foreach ($idFieldNames as $idFieldName) {
                            $paramName = "{$parentEntityAlias}_{$idFieldName}_{$index}";
                            $andConditions->add(
                                $this->queryBuilder->expr()
                                    ->eq("IDENTITY($rootEntityAlias.$association, '$idFieldName')", ":$paramName")
                            );
                            $this->queryBuilder->setParameter($paramName, $parentId[$idFieldName]);
                        }

                        $orConditions->add($andConditions);
                    }

                    $this->queryBuilder->andWhere($orConditions);

                    foreach ($idFieldNames as $idFieldName) {
                        $idNameAlias = "{$parentEntityAlias}_$idFieldName";
                        $this->queryBuilder->addSelect("IDENTITY($rootEntityAlias.$association, '$idFieldName') AS $idNameAlias");
                    }
                } else {
                    $this->queryBuilder->join(
                        "$rootEntityAlias.$association",
                        $parentEntityAlias
                    );

                    // Build OR conditions for composite key matching
                    $orConditions = $this->queryBuilder->expr()->orX();

                    foreach ($parentIds as $index => $parentId) {
                        $andConditions = $this->queryBuilder->expr()->andX();

                        foreach ($idFieldNames as $idFieldName) {
                            $paramName = "{$parentEntityAlias}_{$idFieldName}_{$index}";
                            $andConditions->add(
                                $this->queryBuilder->expr()
                                    ->eq("$parentEntityAlias.$idFieldName", ":$paramName")
                            );
                            $this->queryBuilder->setParameter($paramName, $parentId[$idFieldName]);
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
                                $idNameAlias = "{$parentEntityAlias}_{$idFieldName}_{$targetIdFieldName}";
                                $this->queryBuilder->addSelect("IDENTITY($parentEntityAlias.$idFieldName, '$targetIdFieldName') AS $idNameAlias");
                            }
                        } else {
                            $idNameAlias = "{$parentEntityAlias}_$idFieldName";
                            $this->queryBuilder->addSelect("$parentEntityAlias.$idFieldName AS $idNameAlias");
                        }
                    }
                }
            } else {
                $association = $parentEntity->associationInversedByTargetField($association);

                $this->queryBuilder->join(
                    "$rootEntityAlias.$association",
                    $parentEntityAlias
                );

                $idFieldNames = $parentEntity->idFieldNames();

                // Build OR conditions for composite key matching
                $orConditions = $this->queryBuilder->expr()->orX();

                foreach ($parentIds as $index => $parentId) {
                    $andConditions = $this->queryBuilder->expr()->andX();

                    foreach ($idFieldNames as $idFieldName) {
                        $paramName = "{$parentEntityAlias}_{$idFieldName}_{$index}";
                        $andConditions->add(
                            $this->queryBuilder->expr()
                                ->eq("$parentEntityAlias.$idFieldName", ":$paramName")
                        );
                        $this->queryBuilder->setParameter($paramName, $parentId[$idFieldName]);
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
                            $idNameAlias = "{$parentEntityAlias}_{$idFieldName}_{$targetIdFieldName}";
                            $this->queryBuilder->addSelect("IDENTITY($parentEntityAlias.$idFieldName, '$targetIdFieldName') AS $idNameAlias");
                        }
                    } else {
                        $idNameAlias = "{$parentEntityAlias}_$idFieldName";
                        $this->queryBuilder->addSelect("$parentEntityAlias.$idFieldName AS $idNameAlias");
                    }
                }
            }
        }

        return $this->queryBuilder;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}
