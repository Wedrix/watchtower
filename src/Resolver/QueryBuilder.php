<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;

/**
 * @mixin DoctrineQueryBuilder
 */
interface QueryBuilder
{
    public function join(
        string $join,
        string $alias,
        ?string $conditionType = null,
        mixed $condition = null,
        ?string $indexBy = null
    ): mixed;

    public function innerJoin(
        string $join,
        string $alias,
        ?string $conditionType = null,
        mixed $condition = null,
        ?string $indexBy = null
    ): mixed;

    public function leftJoin(
        string $join,
        string $alias,
        ?string $conditionType = null,
        mixed $condition = null,
        ?string $indexBy = null
    ): mixed;

    public function joinOnce(
        string $path,
        string $alias,
        ?string $conditionType = null,
        mixed $condition = null,
        ?string $indexBy = null
    ): mixed;

    public function leftJoinOnce(
        string $path,
        string $alias,
        ?string $conditionType = null,
        mixed $condition = null,
        ?string $indexBy = null
    ): mixed;

    public function joinAlias(
        string $path,
        string $alias,
        ?string $conditionType = null,
        mixed $condition = null,
        ?string $indexBy = null
    ): string;

    public function leftJoinAlias(
        string $path,
        string $alias,
        ?string $conditionType = null,
        mixed $condition = null,
        ?string $indexBy = null
    ): string;

    public function selectAlias(
        string $alias
    ): string;

    public function parameterName(
        string $name
    ): string;

    /**
     * @return array<string,array<int,string>>
     */
    public function duplicateJoinPaths(): array;

    public function assertNoDuplicateJoins(): void;

    public function registerCursorOrdering(
        string $key,
        string $expression,
        string $direction,
        ParameterType|ArrayParameterType|string|int|null $parameterType = null
    ): void;

    public function enableCursorProjection(): void;

    /**
     * @return array<int,array{key:string,expression:string,direction:'ASC'|'DESC',parameterType:ParameterType|ArrayParameterType|string|int|null,resultAlias:string|null,resultAliasIsInternal:bool}>
     */
    public function cursorOrderings(): array;

    public function reverseOrderings(): void;

    public function identifierAlias(): string;

    public function parentAlias(): string;

    public function rootAlias(): string;
}

function QueryBuilder(
    DoctrineQueryBuilder $doctrineQueryBuilder
): QueryBuilder {
    /**
     * @var \WeakMap<DoctrineQueryBuilder,?QueryBuilder>|null
     */
    static $instances = null;

    if ($instances === null) {
        $instances = new \WeakMap;
    }

    if (! isset($instances[$doctrineQueryBuilder])) {
        $instances[$doctrineQueryBuilder] = null;
    }

    return $instances[$doctrineQueryBuilder] ??= new class(doctrineQueryBuilder: $doctrineQueryBuilder) implements QueryBuilder
    {
        const RESERVED_PREFIXES = [
            '__root',
            '__parent',
            '__primary',
            '__cursor',
        ];

        /**
         * @var array<string,int>
         *
         * The key is the DQL alias and the value is the count
         */
        private array $aliases = [];

        /**
         * @var array<string,int>
         *
         * The key is the parameter name and the value is the count
         */
        private array $parameterNames = [];

        /**
         * @var array<string,string>
         *
         * The key is a normalized join spec and the value is the canonical alias for it.
         */
        private array $joinAliasesBySpec = [];

        /**
         * @var array<string,true>
         *
         * The key is a normalized join spec that has already been added to the DQL.
         */
        private array $joinedSpecs = [];

        /**
         * @var array<string,array{path:string,aliases:array<string,true>}>
         *
         * The key is a normalized join spec. Aliases are only duplicated when the full join spec matches.
         */
        private array $joinedAliasesBySpec = [];

        /**
         * @var array<int,array{key:string,expression:string,direction:'ASC'|'DESC',parameterType:ParameterType|ArrayParameterType|string|int|null,resultAlias:string|null,resultAliasIsInternal:bool}>
         */
        private array $cursorOrderings = [];

        private bool $cursorProjectionEnabled = false;

        private string $identifierAlias = '__primary';

        private string $parentAlias = '__parent';

        private string $rootAlias = '__root';

        public function __construct(
            private DoctrineQueryBuilder $doctrineQueryBuilder
        ) {}

        public function join(
            string $join,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): mixed {
            $conditionType = self::normalizedJoinConditionType($conditionType);

            $result = $this->doctrineQueryBuilder->join(
                $join,
                $alias,
                $conditionType,
                $condition,
                $indexBy
            );

            $this->recordJoin(
                joinType: 'inner',
                path: $join,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );

            return $result;
        }

        public function innerJoin(
            string $join,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): mixed {
            $conditionType = self::normalizedJoinConditionType($conditionType);

            $result = $this->doctrineQueryBuilder->innerJoin(
                $join,
                $alias,
                $conditionType,
                $condition,
                $indexBy
            );

            $this->recordJoin(
                joinType: 'inner',
                path: $join,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );

            return $result;
        }

        public function leftJoin(
            string $join,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): mixed {
            $conditionType = self::normalizedJoinConditionType($conditionType);

            $result = $this->doctrineQueryBuilder->leftJoin(
                $join,
                $alias,
                $conditionType,
                $condition,
                $indexBy
            );

            $this->recordJoin(
                joinType: 'left',
                path: $join,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );

            return $result;
        }

        public function joinOnce(
            string $path,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): mixed {
            return $this->joinOnceUsing(
                joinType: 'inner',
                path: $path,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );
        }

        public function leftJoinOnce(
            string $path,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): mixed {
            return $this->joinOnceUsing(
                joinType: 'left',
                path: $path,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );
        }

        public function joinAlias(
            string $path,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): string {
            return $this->joinAliasUsing(
                joinType: 'inner',
                path: $path,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );
        }

        public function leftJoinAlias(
            string $path,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): string {
            return $this->joinAliasUsing(
                joinType: 'left',
                path: $path,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );
        }

        public function selectAlias(
            string $alias
        ): string {
            return $this->reconciledAlias($alias);
        }

        public function parameterName(
            string $name
        ): string {
            if (! isset($this->parameterNames[$name])) {
                $this->parameterNames[$name] = 1;

                return $name;
            }

            return $name.++$this->parameterNames[$name];
        }

        public function duplicateJoinPaths(): array
        {
            $duplicates = [];

            foreach ($this->joinedAliasesBySpec as $joinedAliases) {
                $aliases = $joinedAliases['aliases'];

                if (\count($aliases) <= 1) {
                    continue;
                }

                $duplicates[$joinedAliases['path']] = \array_merge(
                    $duplicates[$joinedAliases['path']] ?? [],
                    \array_keys($aliases)
                );
            }

            return $duplicates;
        }

        public function assertNoDuplicateJoins(): void
        {
            $duplicates = $this->duplicateJoinPaths();

            if ($duplicates === []) {
                return;
            }

            $summary = \implode(
                '; ',
                \array_map(
                    static fn (string $path, array $aliases): string => "{$path} joined as ".\implode(', ', $aliases),
                    \array_keys($duplicates),
                    \array_values($duplicates)
                )
            );

            throw new DuplicateJoinPathQueryBuilderException("Duplicate join paths detected: {$summary}.");
        }

        public function registerCursorOrdering(
            string $key,
            string $expression,
            string $direction,
            ParameterType|ArrayParameterType|string|int|null $parameterType = null
        ): void {
            $direction = \strtoupper($direction);

            if (! \in_array($direction, ['ASC', 'DESC'], true)) {
                throw new InvalidCursorOrderingDirectionQueryBuilderException("Invalid cursor ordering direction '{$direction}'.");
            }

            if ($key === '') {
                throw new EmptyCursorOrderingKeyQueryBuilderException('Cursor ordering key cannot be empty.');
            }

            if ($expression === '') {
                throw new EmptyCursorOrderingExpressionQueryBuilderException('Cursor ordering expression cannot be empty.');
            }

            $this->cursorOrderings[] = [
                'key' => $key,
                'expression' => $expression,
                'direction' => $direction,
                'parameterType' => $parameterType,
                'resultAlias' => null,
                'resultAliasIsInternal' => false,
            ];

            if ($this->cursorProjectionEnabled) {
                $this->enableCursorProjection();
            }
        }

        public function enableCursorProjection(): void
        {
            $this->cursorProjectionEnabled = true;

            foreach ($this->cursorOrderings as $index => $cursorOrdering) {
                if ($cursorOrdering['resultAlias'] !== null) {
                    continue;
                }

                // Select cursor parts into row-only aliases so QueryResult can assemble _cursor.
                $safeKey = \preg_replace('/[^A-Za-z0-9_]/', '_', $cursorOrdering['key']) ?? 'value';

                if ($safeKey === '' || \ctype_digit($safeKey[0])) {
                    $safeKey = 'value_'.$safeKey;
                }

                $resultAlias = "__cursor_{$index}_{$safeKey}";

                $this->doctrineQueryBuilder->addSelect(
                    "{$cursorOrdering['expression']} AS $resultAlias"
                );

                $this->cursorOrderings[$index]['resultAlias'] = $resultAlias;
                $this->cursorOrderings[$index]['resultAliasIsInternal'] = true;
            }
        }

        public function cursorOrderings(): array
        {
            return $this->cursorOrderings;
        }

        public function reverseOrderings(): void
        {
            $orderByParts = $this->doctrineQueryBuilder->getDQLPart('orderBy');

            if (! \is_array($orderByParts) || empty($orderByParts)) {
                return;
            }

            $reversedOrderings = [];

            foreach ($orderByParts as $orderByPart) {
                if (! $orderByPart instanceof OrderBy) {
                    continue;
                }

                foreach ($orderByPart->getParts() as $orderByItem) {
                    $orderByItem = \trim($orderByItem);

                    if (\preg_match('/\s+(ASC|DESC)$/i', $orderByItem, $matches) === 1) {
                        $direction = \strtoupper($matches[1]);
                        $expression = \trim(\substr($orderByItem, 0, -\strlen($matches[0])));
                        $reversedOrderings[] = [
                            $expression,
                            $direction === 'ASC' ? 'DESC' : 'ASC',
                        ];

                        continue;
                    }

                    // Doctrine defaults directionless ORDER BY items to ASC, so reverse them to DESC.
                    $reversedOrderings[] = [$orderByItem, 'DESC'];
                }
            }

            if (empty($reversedOrderings)) {
                return;
            }

            $this->doctrineQueryBuilder->resetDQLPart('orderBy');

            foreach ($reversedOrderings as [$expression, $direction]) {
                $this->doctrineQueryBuilder->addOrderBy($expression, $direction);
            }
        }

        public function identifierAlias(): string
        {
            return $this->identifierAlias;
        }

        public function parentAlias(): string
        {
            return $this->parentAlias;
        }

        public function rootAlias(): string
        {
            return $this->rootAlias;
        }

        private function reconciledAlias(
            string $alias
        ): string {
            // Remove reserved prefixes from the alias
            foreach (self::RESERVED_PREFIXES as $prefix) {
                if (\str_starts_with($alias, $prefix)) {
                    throw new ReservedAliasQueryBuilderException("Alias '{$alias}' uses reserved prefix '{$prefix}'");
                }
            }

            if (! isset($this->aliases[$alias])) {
                $this->aliases[$alias] = 1;

                return $alias;
            }

            return $alias.++$this->aliases[$alias];
        }

        private function joinOnceUsing(
            string $joinType,
            string $path,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): mixed {
            $conditionType = self::normalizedJoinConditionType($conditionType);

            $specKey = $this->joinSpecKey(
                joinType: $joinType,
                path: $path,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );

            if (isset($this->joinAliasesBySpec[$specKey])) {
                $existingAlias = $this->joinAliasesBySpec[$specKey];

                if ($existingAlias !== $alias) {
                    $aliasMethod = $joinType === 'left' ? 'leftJoinAlias()' : 'joinAlias()';
                    $joinMethod = $joinType === 'left' ? 'leftJoinOnce()' : 'joinOnce()';

                    throw new ConflictingJoinAliasQueryBuilderException(
                        "Join '{$path}' is already joined as '{$existingAlias}', not '{$alias}'. Use {$aliasMethod} before {$joinMethod} when composing plugins."
                    );
                }

                if (isset($this->joinedSpecs[$specKey])) {
                    return $this->doctrineQueryBuilder;
                }
            }

            if ($joinType === 'left') {
                return $this->leftJoin(
                    join: $path,
                    alias: $alias,
                    conditionType: $conditionType,
                    condition: $condition,
                    indexBy: $indexBy
                );
            }

            return $this->join(
                join: $path,
                alias: $alias,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );
        }

        private function joinAliasUsing(
            string $joinType,
            string $path,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): string {
            $conditionType = self::normalizedJoinConditionType($conditionType);

            $specKey = $this->joinSpecKey(
                joinType: $joinType,
                path: $path,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );

            if (isset($this->joinAliasesBySpec[$specKey])) {
                return $this->joinAliasesBySpec[$specKey];
            }

            return $this->joinAliasesBySpec[$specKey] = $this->reconciledAlias($alias);
        }

        private function recordJoin(
            string $joinType,
            string $path,
            string $alias,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): void {
            $normalizedPath = self::normalizeJoinPath($path);
            $specKey = $this->joinSpecKey(
                joinType: $joinType,
                path: $path,
                conditionType: $conditionType,
                condition: $condition,
                indexBy: $indexBy
            );

            $this->reserveAlias($alias);

            $this->joinAliasesBySpec[$specKey] ??= $alias;
            $this->joinedSpecs[$specKey] = true;
            $this->joinedAliasesBySpec[$specKey]['path'] = $normalizedPath;
            $this->joinedAliasesBySpec[$specKey]['aliases'][$alias] = true;

            if ($this->strictDuplicateJoinDetectionEnabled()) {
                $this->assertNoDuplicateJoins();
            }
        }

        private function reserveAlias(
            string $alias
        ): void {
            $this->aliases[$alias] ??= 1;
        }

        private function joinSpecKey(
            string $joinType,
            string $path,
            ?string $conditionType = null,
            mixed $condition = null,
            ?string $indexBy = null
        ): string {
            return \json_encode(
                [
                    'joinType' => $joinType,
                    'path' => self::normalizeJoinPath($path),
                    'conditionType' => $conditionType,
                    'condition' => self::normalizeJoinValue($condition),
                    'indexBy' => $indexBy,
                ],
                \JSON_THROW_ON_ERROR
            );
        }

        private static function normalizeJoinPath(
            string $path
        ): string {
            return \preg_replace('/\s+/', ' ', \trim($path)) ?? \trim($path);
        }

        private static function normalizeJoinValue(
            mixed $value
        ): string {
            if ($value === null) {
                return '';
            }

            if (\is_scalar($value)) {
                return (string) $value;
            }

            if ($value instanceof \Stringable) {
                return (string) $value;
            }

            try {
                return \serialize($value);
            } catch (\Throwable) {
                return \is_object($value) ? $value::class : \gettype($value);
            }
        }

        /**
         * @return 'ON'|'WITH'|null
         */
        private static function normalizedJoinConditionType(
            ?string $conditionType
        ): ?string {
            if ($conditionType === null) {
                return null;
            }

            $conditionType = \strtoupper($conditionType);

            if (! \in_array($conditionType, [Join::ON, Join::WITH], true)) {
                throw new InvalidJoinConditionTypeQueryBuilderException("Invalid join condition type '{$conditionType}'.");
            }

            return $conditionType;
        }

        private function strictDuplicateJoinDetectionEnabled(): bool
        {
            $value = $_SERVER['WATCHTOWER_STRICT_QUERY_SHAPE']
                ?? $_ENV['WATCHTOWER_STRICT_QUERY_SHAPE']
                ?? \getenv('WATCHTOWER_STRICT_QUERY_SHAPE');

            return \in_array(
                \strtolower((string) $value),
                ['1', 'true', 'yes', 'on'],
                true
            );
        }

        /**
         * Magic method to handle calls to undefined methods.
         * If the method exists on the Doctrine QueryBuilder instance, it proxies the call to it.
         *
         * @param  string  $name  The name of the method being called.
         * @param  array<int,mixed>  $arguments  The arguments passed to the method.
         * @return mixed The result of the proxied method call.
         *
         * @throws UnknownMethodQueryBuilderException If the method does not exist on the Doctrine QueryBuilder instance.
         */
        public function __call(string $name, array $arguments): mixed
        {
            if (! \method_exists($this->doctrineQueryBuilder, $name)) {
                throw new UnknownMethodQueryBuilderException("Method {$name} does not exist on ".self::class);
            }

            return $this->doctrineQueryBuilder->$name(...$arguments);
        }
    };
}
