<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

trait MaybeCursorPaginatedQuery
{
    public function __construct(
        private Node $node,
        private QueryBuilder $queryBuilder,
        private bool $isWorkable
    ) {
        if (! $this->isWorkable) {
            return;
        }

        $queryParams = $this->node->args()['queryParams'] ?? [];

        if (empty($queryParams)) {
            return;
        }

        $after = $queryParams['after'] ?? null;
        $before = $queryParams['before'] ?? null;

        if ($after === null && $before === null) {
            return;
        }

        if ($after !== null && $before !== null) {
            throw new CursorAfterAndBeforeQueryException('Invalid query. The after and before parameters cannot be used together.');
        }

        if (($queryParams['page'] ?? null) !== null) {
            throw new CursorWithPageQueryException('Invalid query. The page parameter cannot be combined with cursor pagination.');
        }

        $cursor = $after ?? $before;

        if (! \is_array($cursor)) {
            throw new InvalidCursorValueQueryException('Invalid Cursor value. The Cursor scalar must parse to an array.');
        }

        $isBeforeCursor = $before !== null;

        $cursorOrderings = $this->queryBuilder->cursorOrderings();

        if (empty($cursorOrderings)) {
            throw new MissingCursorOrderingMetadataQueryException('Invalid query. Cursor pagination requires cursor-capable ordering metadata from an active ordering plugin.');
        }

        $cursorParameterNames = [];

        foreach ($cursorOrderings as $index => $cursorOrdering) {
            // Cursor keys can be arbitrary API names; Doctrine parameter names need safe identifiers.
            $safeKey = \preg_replace('/[^A-Za-z0-9_]/', '_', $cursorOrdering['key']) ?? 'value';

            if ($safeKey === '' || \ctype_digit($safeKey[0])) {
                $safeKey = 'value_'.$safeKey;
            }

            $cursorParameterNames[$index] = "cursor_{$index}_{$safeKey}";
        }

        $orConditions = $this->queryBuilder->expr()->orX();

        foreach ($cursorOrderings as $index => $cursorOrdering) {
            $andConditions = $this->queryBuilder->expr()->andX();

            for ($previousIndex = 0; $previousIndex < $index; $previousIndex++) {
                $previousCursorOrdering = $cursorOrderings[$previousIndex];
                $previousParamName = $cursorParameterNames[$previousIndex];

                $andConditions->add(
                    $this->queryBuilder->expr()
                        ->eq($previousCursorOrdering['expression'], ":$previousParamName")
                );
            }

            $paramName = $cursorParameterNames[$index];
            $comparator = $cursorOrdering['direction'] === 'ASC' ? '>' : '<';

            // A before cursor walks the same ordering from the other side.
            if ($isBeforeCursor) {
                $comparator = $comparator === '>' ? '<' : '>';
            }

            $expression = $cursorOrdering['expression'];

            $andConditions->add(
                $comparator === '>'
                    ? $this->queryBuilder->expr()->gt($expression, ":$paramName")
                    : $this->queryBuilder->expr()->lt($expression, ":$paramName")
            );

            $orConditions->add($andConditions);
        }

        foreach ($cursorOrderings as $index => $cursorOrdering) {
            $key = $cursorOrdering['key'];

            if (! \array_key_exists($key, $cursor)) {
                throw new MissingCursorKeyQueryException("Invalid Cursor value. Missing cursor key '$key'.");
            }

            $value = $cursor[$key];

            if (! \is_scalar($value)) {
                throw new InvalidCursorKeyValueQueryException("Invalid Cursor value. Cursor key '$key' must contain a scalar value.");
            }

            $paramName = $cursorParameterNames[$index];
            $parameterType = $cursorOrdering['parameterType'];

            if ($parameterType === null) {
                $this->queryBuilder->setParameter($paramName, $value);

                continue;
            }

            $this->queryBuilder->setParameter($paramName, $value, $parameterType);
        }

        if ($isBeforeCursor) {
            $this->queryBuilder->reverseOrderings();
        }

        $this->queryBuilder->andWhere($orConditions);
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
