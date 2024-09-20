<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

final class Node
{
    private readonly string $name;

    private readonly string $unwrappedType;

    private readonly string $unwrappedParentType;

    private readonly string $operation;

    private readonly bool $isNullable;

    private readonly bool $isACollection;

    private readonly bool $isTopLevel;

    private readonly bool $isAbstract;

    private readonly bool $isALeaf;

    /**
     * @var array<string,mixed>
     */
    private readonly array $concreteFieldsSelection;

    /**
     * @var array<string,mixed>
     */
    private readonly array $abstractFieldsSelection;

    /**
     * @param array<string,mixed> $root
     * @param array<string,mixed> $args
     * @param array<string,mixed> $context
     */
    public function __construct(
        private readonly array $root,
        private readonly array $args,
        private readonly array $context,
        private readonly ResolveInfo $info
    )
    {
        $this->name = $this->info->fieldName;

        $this->unwrappedType = \str_replace(['[',']','!'], "", (string) $this->info->returnType);

        $this->unwrappedParentType = \str_replace(['[',']','!'], "", (string) $this->info->parentType);

        $this->operation = $this->info->operation?->operation 
            ?? throw new \Exception('Invalid Query. The operation is not defined.');

        $this->isNullable = !\str_ends_with((string) $this->info->returnType, '!');

        $this->isACollection = \str_starts_with((string) $this->info->returnType, '[')
            && (\str_ends_with((string) $this->info->returnType, ']') 
                || \str_ends_with((string) $this->info->returnType, ']!')
            );

        $this->isTopLevel = \count($this->info->path) === 1;

        $this->isAbstract = Type::isAbstractType(Type::getNullableType($this->info->returnType));

        $this->isALeaf = Type::isLeafType(Type::getNullableType($this->info->returnType));

        $this->concreteFieldsSelection = $this->info->lookAhead(['group-implementor-fields'])->queryPlan()['fields'] ?? [];

        $this->abstractFieldsSelection = $this->info->lookAhead(['group-implementor-fields'])->queryPlan()['implementors'] ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function root(): array
    {
        return $this->root;
    }

    /**
     * @return array<string,mixed>
     */
    public function args(): array
    {
        return $this->args;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unwrappedType(): string
    {
        return $this->unwrappedType;
    }

    public function unwrappedParentType(): string
    {
        return $this->unwrappedParentType;
    }

    /**
     * One of 'query', 'mutation', or 'subscription'
     */
    public function operation(): string
    {
        return $this->operation;
    }

    public function isNullable(): bool
    {
        return $this->isNullable;
    }

    public function isACollection(): bool
    {
        return $this->isACollection;
    }

    public function isTopLevel(): bool
    {
        return $this->isTopLevel;
    }

    public function isAbstract(): bool
    {
        return $this->isAbstract;
    }

    public function isALeaf(): bool
    {
        return $this->isALeaf;
    }
    
    /**
     * Example:
     * 
     * query {
     *  item {
     *          id
     *          owner
     *          ... on Car {
     *              mark
     *              model
     *          }
     *          ... on Building {
     *              city
     *          }
     *          ...BuildingFragment
     *     }
     *   }
     * }
     * 
     * fragment BuildingFragment on Building {
     *     address
     * }
     * 
     * if current node corresponds to item field, returns:
     *     [             
     *       'id' => [
     *          'type'   => Int!,
     *          'fields' => [],
     *          'args'   => [],
     *       ],
     *       'owner' => [
     *          'type'   => String!,
     *          'fields' => [],
     *          'args'   => [],
     *        ],
     *     ]
     * 
     * @return array<string,mixed>
     */
    public function concreteFieldsSelection(): array
    {
        return $this->concreteFieldsSelection;
    }

    /**
     * Example:
     * 
     * query {
     *  item {
     *          id
     *          owner
     *          ... on Car {
     *              mark
     *              model
     *          }
     *          ... on Building {
     *              city
     *          }
     *          ...BuildingFragment
     *     }
     *   }
     * }
     * 
     * fragment BuildingFragment on Building {
     *     address
     * }
     * 
     * if current node corresponds to item field, returns:
     *          'Car'      => [
     *              'type'   => Car!,
     *              'fields' => [
     *                  'mark'  => [
     *                      'type'   => String!,
     *                      'fields' => [],
     *                      'args'   => [],
     *                  ],
     *                  'model' => [
     *                      'type'   => String!,
     *                      'fields' => [],
     *                      'args'   => [],
     *                  ],
     *              ],
     *          ],
     *          'Building' => [
     *              'type'   => Building!,
     *              'fields' => [
     *                  'city'    => [
     *                      'type'   => String!,
     *                      'fields' => [],
     *                      'args'   => [],
     *                  ],
     *                  'address' => [
     *                      'type'   => String!,
     *                      'fields' => [],
     *                      'args'   => [],
     *                  ],
     *              ],
     *          ],
     * 
     * @return array<string,mixed>
     */
    public function abstractFieldsSelection(): array
    {
        return $this->abstractFieldsSelection;
    }

    public function info(): ResolveInfo
    {
        return $this->info;
    }
}