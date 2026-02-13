<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

interface Node
{
    /**
     * @return array<string,mixed>
     */
    public function root(): array;

    /**
     * @return array<string,mixed>
     */
    public function args(): array;

    /**
     * @return array<string,mixed>
     */
    public function context(): array;

    public function name(): string;

    public function unwrappedType(): string;

    public function unwrappedParentType(): string;

    /**
     * One of 'query', 'mutation', or 'subscription'
     */
    public function operation(): string;

    public function isNullable(): bool;

    public function isACollection(): bool;

    public function isTopLevel(): bool;

    public function isAbstract(): bool;

    public function isALeaf(): bool;

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
    public function concreteFieldsSelection(): array;

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
    public function abstractFieldsSelection(): array;

    public function info(): ResolveInfo;
}

/**
 * @param  array<string,mixed>  $root
 * @param  array<string,mixed>  $args
 * @param  array<string,mixed>  $context
 */
function Node(
    array $root,
    array $args,
    array $context,
    ResolveInfo $info
): Node {
    return new class(root: $root, args: $args, context: $context, info: $info) implements Node
    {
        private string $name;

        private string $unwrappedType;

        private string $unwrappedParentType;

        private string $operation;

        private bool $isNullable;

        private bool $isACollection;

        private bool $isTopLevel;

        private bool $isAbstract;

        private bool $isALeaf;

        /**
         * @var array<string,mixed>
         */
        private array $concreteFieldsSelection;

        /**
         * @var array<string,mixed>
         */
        private array $abstractFieldsSelection;

        /**
         * @param  array<string,mixed>  $root
         * @param  array<string,mixed>  $args
         * @param  array<string,mixed>  $context
         */
        public function __construct(
            private array $root,
            private array $args,
            private array $context,
            private ResolveInfo $info
        ) {
            $this->name = $this->info->fieldName;

            $this->unwrappedType = \str_replace(['[', ']', '!'], '', (string) $this->info->returnType);

            $this->unwrappedParentType = \str_replace(['[', ']', '!'], '', (string) $this->info->parentType);

            $this->operation = (string) $this->info->operation->operation;

            $this->isNullable = ! \str_ends_with((string) $this->info->returnType, '!');

            $this->isACollection = \str_starts_with((string) $this->info->returnType, '[')
                && (\str_ends_with((string) $this->info->returnType, ']')
                    || \str_ends_with((string) $this->info->returnType, ']!')
                );

            $this->isTopLevel = \count($this->info->path) === 1;

            $this->isAbstract = Type::isAbstractType(Type::getNullableType($this->info->returnType));

            $this->isALeaf = Type::isLeafType(Type::getNullableType($this->info->returnType));

            $queryPlan = $this->info
                ->lookAhead(['groupImplementorFields' => true])
                ->queryPlan();

            $this->concreteFieldsSelection = $queryPlan['fields'] ?? $queryPlan;

            $this->abstractFieldsSelection = $queryPlan['implementors'] ?? [];
        }

        public function root(): array
        {
            return $this->root;
        }

        public function args(): array
        {
            return $this->args;
        }

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

        public function concreteFieldsSelection(): array
        {
            return $this->concreteFieldsSelection;
        }

        public function abstractFieldsSelection(): array
        {
            return $this->abstractFieldsSelection;
        }

        public function info(): ResolveInfo
        {
            return $this->info;
        }
    };
}
