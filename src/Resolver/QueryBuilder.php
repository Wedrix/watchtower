<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query\Expr\OrderBy;
use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;

/**
 * @mixin DoctrineQueryBuilder
 */
interface QueryBuilder
{
    public function registerCursorOrdering(
        string $key,
        string $expression,
        string $direction,
        ParameterType|ArrayParameterType|string|int|null $parameterType = null
    ): void;

    public function enableCursorProjection(): void;

    public function cursorProjectionEnabled(): bool;

    /**
     * @return array<int,array{key:string,expression:string,direction:'ASC'|'DESC',parameterType:ParameterType|ArrayParameterType|string|int|null,resultAlias:string|null}>
     */
    public function cursorOrderings(): array;

    public function reverseOrderings(): void;

    public function identifierAlias(): string;

    public function parentEntityAlias(): string;

    public function rootEntityAlias(): string;

    public function reconciledAlias(
        string $alias
    ): string;
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
         * The key is the alias and the value is the count
         */
        private array $aliases = [];

        /**
         * @var array<int,array{key:string,expression:string,direction:'ASC'|'DESC',parameterType:ParameterType|ArrayParameterType|string|int|null,resultAlias:string|null}>
         */
        private array $cursorOrderings = [];

        private bool $cursorProjectionEnabled = false;

        private string $identifierAlias = '__primary';

        private string $parentEntityAlias = '__parent';

        private string $rootEntityAlias = '__root';

        public function __construct(
            private DoctrineQueryBuilder $doctrineQueryBuilder
        ) {}

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
            }
        }

        public function cursorProjectionEnabled(): bool
        {
            return $this->cursorProjectionEnabled;
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

        public function parentEntityAlias(): string
        {
            return $this->parentEntityAlias;
        }

        public function rootEntityAlias(): string
        {
            return $this->rootEntityAlias;
        }

        public function reconciledAlias(
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
