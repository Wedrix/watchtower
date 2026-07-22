<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;

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

    /**
     * @return array<string, mixed>
     */
    public function associationDirective(): array;

    /**
     * @return array<string, mixed>
     */
    public function parentId(): array;
}
