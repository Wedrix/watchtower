<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Doctrine\ORM\QueryBuilder as DoctrineQueryBuilder;

/**
 * @mixin DoctrineQueryBuilder
 */
interface QueryBuilder
{
    public function rootAlias(): string;

    public function reconciledAlias(
        string $alias
    ): string;
}

function QueryBuilder(
    DoctrineQueryBuilder $doctrineQueryBuilder
): QueryBuilder
{
    /**
     * @var \WeakMap<DoctrineQueryBuilder,QueryBuilder>
     */
    static $instances = new \WeakMap();

    return $instances[$doctrineQueryBuilder] ??= new class(
        doctrineQueryBuilder: $doctrineQueryBuilder
    ) implements QueryBuilder {
        /**
         * @var array<string,int>
         * 
         * The key is the alias and the value is the count
         */
        private array $aliases = [];
    
        public function __construct(
            private readonly DoctrineQueryBuilder $doctrineQueryBuilder
        ){}
    
        public function rootAlias(): string
        {
            return $this->doctrineQueryBuilder
                        ->getRootAliases()[0] ?? throw new \Exception("Invalid Query. The rootAlias is unset.");
        }
    
        public function reconciledAlias(
            string $alias
        ): string
        {
            if (!isset($this->aliases[$alias])) {
                $this->aliases[$alias] = 1;
    
                return $alias;
            }
    
            return $alias.++$this->aliases[$alias];
        }
    
        /**
         * Magic method to handle calls to undefined methods.
         * If the method exists on the Doctrine QueryBuilder instance, it proxies the call to it.
         *
         * @param string $name The name of the method being called.
         * @param array<int,mixed> $arguments The arguments passed to the method.
         *
         * @return mixed The result of the proxied method call.
         *
         * @throws \BadMethodCallException If the method does not exist on the Doctrine QueryBuilder instance.
         */
        public function __call(string $name, array $arguments): mixed
        {
            if (!\method_exists($this->doctrineQueryBuilder, $name)) {
                throw new \BadMethodCallException("Method {$name} does not exist on " . self::class);
            }
    
            return $this->doctrineQueryBuilder->$name(...$arguments);
        }
    };
}