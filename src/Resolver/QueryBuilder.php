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

    /**
     * @return array<int,array{key:string,expression:string,direction:'ASC'|'DESC',parameterType:ParameterType|ArrayParameterType|string|int|null}>
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
        ];

        /**
         * @var array<string,int>
         *
         * The key is the alias and the value is the count
         */
        private array $aliases = [];

        /**
         * @var array<int,array{key:string,expression:string,direction:'ASC'|'DESC',parameterType:ParameterType|ArrayParameterType|string|int|null}>
         */
        private array $cursorOrderings = [];

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
            ];
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
                    $reversedOrderings[] = $this->reverseOrdering($orderByItem);
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

        /**
         * @return array{0:string,1:'ASC'|'DESC'}
         */
        private function reverseOrdering(string $ordering): array
        {
            $ordering = \trim($ordering);

            if (\preg_match('/\s+(ASC|DESC)$/i', $ordering, $matches) === 1) {
                $direction = \strtoupper($matches[1]);
                $expression = \trim(\substr($ordering, 0, -\strlen($matches[0])));

                return [
                    $expression,
                    $direction === 'ASC' ? 'DESC' : 'ASC',
                ];
            }

            return [$ordering, 'DESC'];
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
