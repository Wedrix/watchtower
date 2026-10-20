<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Executor\Values;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\ObjectType;
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

    /**
     * @return array<string, mixed>
     */
    public function associationDirective(): array;

    /**
     * @return array<string, mixed>
     */
    public function parentId(): array;
}

/** @internal
 * @return array{fields:array<string,mixed>,implementors:array<string,mixed>}
 */
function nodeFieldsSelection(ResolveInfo $info): array
{
    return nodeSelectionPlan(Type::getNamedType($info->returnType), $info->fieldNodes, $info);
}

/** @internal
 * @param  iterable<FieldNode>  $nodes
 * @return array{fields:array<string,mixed>,implementors:array<string,mixed>}
 */
function nodeSelectionPlan(Type $type, iterable $nodes, ResolveInfo $info): array
{
    $sets = [];
    foreach ($nodes as $node) {
        if ($node->selectionSet !== null) {
            $sets[] = $node->selectionSet;
        }
    }
    if ($type instanceof ObjectType) {
        return ['fields' => nodeSelectedFields($type, $sets, $info), 'implementors' => []];
    }
    if (! $type instanceof AbstractType) {
        return ['fields' => [], 'implementors' => []];
    }

    $implementors = [];
    $common = null;
    foreach ($info->schema->getPossibleTypes($type) as $objectType) {
        $fields = nodeSelectedFields($objectType, $sets, $info);
        $implementors[$objectType->name] = ['type' => $objectType, 'fields' => $fields];
        // Only selections made on an abstract type belong to the common map.
        // Identical names inside two concrete fragments remain implementor fields.
        $shared = nodeSelectedFields($objectType, $sets, $info, true);
        $common = $common === null ? $shared : \array_intersect_key($common, $shared);
        foreach ($common as $name => $field) {
            if ($field != $shared[$name]) {
                unset($common[$name]);
            }
        }
    }
    $common ??= [];
    foreach ($implementors as $name => &$implementor) {
        foreach ($common as $fieldName => $field) {
            if (($implementor['fields'][$fieldName] ?? null) == $field) {
                unset($implementor['fields'][$fieldName]);
            }
        }
        if ($implementor['fields'] === []) {
            unset($implementors[$name]);
        }
    }

    return ['fields' => $common, 'implementors' => $implementors];
}

/**
 * @internal
 *
 * @param  array<SelectionSetNode>  $sets
 * @return array<string,mixed>
 */
function nodeSelectedFields(ObjectType $type, array $sets, ResolveInfo $info, bool $commonOnly = false): array
{
    /** @param array<string,list<FieldNode>> $groups
     * @param  array<string,true>  $visited
     */
    $collect = static function (ObjectType $type, SelectionSetNode $set, array &$groups, array &$visited, bool $commonOnly = false) use (&$collect, $info): void {
        foreach ($set->selections as $selection) {
            if (! $selection instanceof FieldNode && ! $selection instanceof FragmentSpreadNode && ! $selection instanceof InlineFragmentNode) {
                continue;
            }
            $skip = Values::getDirectiveValues(Directive::skipDirective(), $selection, $info->variableValues);
            $include = Values::getDirectiveValues(Directive::includeDirective(), $selection, $info->variableValues);
            if (($skip['if'] ?? false) === true || ($include['if'] ?? true) === false) {
                continue;
            }
            if ($selection instanceof FieldNode) {
                $groups[$selection->alias->value ?? $selection->name->value][] = $selection;

                continue;
            }
            if ($selection instanceof FragmentSpreadNode) {
                $name = $selection->name->value;
                if (isset($visited[$name])) {
                    continue;
                }
                $visited[$name] = true;
                $fragment = $info->fragments[$name] ?? null;
            } else {
                $fragment = $selection;
            }
            if ($fragment === null) {
                continue;
            }
            $condition = $fragment->typeCondition === null ? $type : $info->schema->getType($fragment->typeCondition->name->value);
            if ($commonOnly && ! $condition instanceof AbstractType) {
                continue;
            }
            if ($condition === $type || ($condition instanceof AbstractType && $info->schema->isSubType($condition, $type))) {
                $collect($type, $fragment->selectionSet, $groups, $visited, $commonOnly);
            }
        }
    };

    $groups = [];
    $visited = [];
    foreach ($sets as $set) {
        $collect($type, $set, $groups, $visited, $commonOnly);
    }
    $fields = [];
    foreach ($groups as $nodes) {
        $name = $nodes[0]->name->value;
        if ($name === '__typename') {
            continue;
        }
        $definition = $type->getField($name);
        $fieldType = $definition->getType();
        $plan = nodeSelectionPlan(Type::getNamedType($fieldType), $nodes, $info);
        $entry = [
            'type' => $fieldType,
            'fields' => $plan['fields'],
            'args' => Values::getArgumentValues($definition, $nodes[0], $info->variableValues),
        ];
        if ($plan['implementors'] !== []) {
            $entry['implementors'] = $plan['implementors'];
        }
        $fields[$name] = isset($fields[$name])
            ? mergeNodeFieldSelections($fields[$name], $entry)
            : $entry;
    }

    return $fields;
}

/**
 * @internal
 *
 * @param  array<string,mixed>  $left
 * @param  array<string,mixed>  $right
 * @return array<string,mixed>
 */
function mergeNodeFields(array $left, array $right): array
{
    foreach ($right as $name => $entry) {
        $left[$name] = isset($left[$name]) ? mergeNodeFieldSelections($left[$name], $entry) : $entry;
    }

    return $left;
}

/**
 * @internal
 *
 * @param  array<string,mixed>  $left
 * @param  array<string,mixed>  $right
 * @return array<string,mixed>
 */
function mergeNodeFieldSelections(array $left, array $right): array
{
    $variants = $left['variants'] ?? [$left];
    foreach ($right['variants'] ?? [$right] as $incoming) {
        $matched = false;
        foreach ($variants as &$variant) {
            if (\Wedrix\Watchtower\argumentValuesKey($variant['args']) === \Wedrix\Watchtower\argumentValuesKey($incoming['args'])) {
                $variant['fields'] = mergeNodeFields($variant['fields'], $incoming['fields']);
                foreach ($incoming['implementors'] ?? [] as $type => $implementor) {
                    $existing = $variant['implementors'][$type]['fields'] ?? [];
                    $variant['implementors'][$type] = $implementor;
                    $variant['implementors'][$type]['fields'] = mergeNodeFields($existing, $implementor['fields']);
                }
                $matched = true;
                break;
            }
        }
        unset($variant);
        if (! $matched) {
            $variants[] = $incoming;
        }
    }
    $entry = $variants[0];
    foreach (\array_slice($variants, 1) as $variant) {
        $entry['fields'] = mergeNodeFields($entry['fields'], $variant['fields']);
    }
    if (\count($variants) > 1) {
        $entry['variants'] = $variants;
    }

    return $entry;
}
