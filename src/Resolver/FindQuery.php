<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use function Wedrix\Watchtower\all_in_array;

final class FindQuery implements Query
{
    private readonly EntityManager $entityManager;

    private readonly bool $isWorkable;

    private readonly QueryBuilder $queryBuilder;

    public function __construct(
        private readonly Query $query,
        private readonly Node $node
    )
    {
        $this->entityManager = (function (): EntityManager {
            return $this->query->builder()->getEntityManager();
        })();

        $this->isWorkable = (function (): bool {
            return $this->node->isTopLevel() 
                && $this->node->operation() === 'query'
                && $this->query->isWorkable();
        })();

        $this->queryBuilder = (function (): QueryBuilder {
            $queryBuilder = $this->query->builder();

            if ($this->isWorkable) {
                $args = $this->node->args();

                $idFields = $this->entityManager->findEntity(name: $this->node->unwrappedType())->idFields();

                if (!all_in_array($idFields, \array_keys($args))) {
                    throw new \Exception("Invalid query! Primary key arguments are required for this kind of query.");
                }

                $idArgs = array_filter(
                    $args,
                    fn (string $argName) => in_array($argName, $idFields),
                    \ARRAY_FILTER_USE_KEY
                );
    
                foreach ($idArgs as $idField => $idValue) {
                    $valueAlias = $queryBuilder->reconciledAlias("__{$idField}Value");
        
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()
                                    ->eq("{$queryBuilder->rootAlias()}.$idField", ":$valueAlias")
                    )->setParameter($valueAlias, $idValue);
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