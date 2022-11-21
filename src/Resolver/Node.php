<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

final class Node
{
    private readonly string $fieldName;

    private readonly string $type;

    private readonly string $parentType;

    private readonly string $operationType;

    private readonly bool $isNullable;

    private readonly bool $isACollection;

    private readonly bool $isTopLevel;

    private readonly bool $isAbstractType;

    private readonly bool $isLeafType;

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
     */
    public function __construct(
        private readonly array $root,
        private readonly array $args,
        private readonly ResolveInfo $info
    )
    {
        $this->fieldName = (function (): string {
            return $this->info->fieldName;
        })();

        $this->type = (function (): string {
            return str_replace(['[',']','!'], "", (string) $this->info->returnType);
        })();

        $this->parentType = (function (): string {
            return str_replace(['[',']','!'], "", (string) $this->info->parentType);
        })();

        $this->operationType = (function (): string {
            return $this->info->operation?->operation 
                ?? throw new \Exception("Invalid Query. The operation is not defined.");
        })();

        $this->isNullable = (function (): bool {
            return !str_ends_with((string) $this->info->returnType, '!');
        })();

        $this->isACollection = (function (): bool {
            return str_starts_with((string) $this->info->returnType, '[')
                && (str_ends_with((string) $this->info->returnType, ']') 
                    || str_ends_with((string) $this->info->returnType, ']!')
                );
        })();

        $this->isTopLevel = (function (): bool {
            return count($this->info->path) === 1;
        })();

        $this->isAbstractType = (function (): bool {
            return Type::isAbstractType(Type::getNullableType($this->info->returnType));
        })();

        $this->isLeafType = (function (): bool {
            return Type::isLeafType(Type::getNullableType($this->info->returnType));
        })();

        $this->concreteFieldsSelection = (function (): array {
            return $this->info->lookAhead(['group-implementor-fields'])->queryPlan()['fields'] ?? [];
        })();

        $this->abstractFieldsSelection = (function (): array {
            return $this->info->lookAhead(['group-implementor-fields'])->queryPlan()['implementors'] ?? [];
        })();
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

    public function fieldName(): string
    {
        return $this->fieldName;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function parentType(): string
    {
        return $this->parentType;
    }

    /**
     * One of 'query','mutation', or 'subscription'
     */
    public function operationType(): string
    {
        return $this->operationType;
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

    public function isAbstractType(): bool
    {
        return $this->isAbstractType;
    }

    public function isLeafType(): bool
    {
        return $this->isLeafType;
    }
}