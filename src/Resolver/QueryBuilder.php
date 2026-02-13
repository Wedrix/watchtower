<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;

/**
 * @mixin DoctrineQueryBuilder
 */
interface QueryBuilder
{
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

        private string $identifierAlias = '__primary';

        private string $parentEntityAlias = '__parent';

        private string $rootEntityAlias = '__root';

        public function __construct(
            private DoctrineQueryBuilder $doctrineQueryBuilder
        ) {}

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
                    throw new \InvalidArgumentException("Alias '{$alias}' uses reserved prefix '{$prefix}'");
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
         * @throws \BadMethodCallException If the method does not exist on the Doctrine QueryBuilder instance.
         */
        public function __call(string $name, array $arguments): mixed
        {
            if (! \method_exists($this->doctrineQueryBuilder, $name)) {
                throw new \BadMethodCallException("Method {$name} does not exist on ".self::class);
            }

            return $this->doctrineQueryBuilder->$name(...$arguments);
        }
    };
}
