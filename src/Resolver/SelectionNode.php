<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use GraphQL\Type\Definition\ResolveInfo;

/**
 * View of one argument variant for a selector; the wrapped node remains unchanged.
 *
 * @param  array<string,mixed>  $selection
 */
function SelectionNode(Node $node, string $field, array $selection): Node
{
    return new class($node, $field, $selection) implements Node
    {
        /** @param array<string,mixed> $selection */
        public function __construct(private Node $node, private string $field, private array $selection)
        {
            ExecutionContexts()->associate($this, ExecutionContexts()->of($this->node));
        }

        /** @return array<string,mixed> */
        public function concreteFieldsSelection(): array
        {
            return \array_replace($this->node->concreteFieldsSelection(), [$this->field => $this->selection]);
        }

        /** @return array<string,mixed> */
        public function root(): array
        {
            return $this->node->root();
        }

        /** @return array<string,mixed> */
        public function args(): array
        {
            return $this->node->args();
        }

        /** @return array<string,mixed> */
        public function context(): array
        {
            return $this->node->context();
        }

        public function name(): string
        {
            return $this->node->name();
        }

        public function unwrappedType(): string
        {
            return $this->node->unwrappedType();
        }

        public function unwrappedParentType(): string
        {
            return $this->node->unwrappedParentType();
        }

        public function operation(): string
        {
            return $this->node->operation();
        }

        public function isNullable(): bool
        {
            return $this->node->isNullable();
        }

        public function isACollection(): bool
        {
            return $this->node->isACollection();
        }

        public function isTopLevel(): bool
        {
            return $this->node->isTopLevel();
        }

        public function isAbstract(): bool
        {
            return $this->node->isAbstract();
        }

        public function isALeaf(): bool
        {
            return $this->node->isALeaf();
        }

        /** @return array<string,mixed> */
        public function abstractFieldsSelection(): array
        {
            return $this->node->abstractFieldsSelection();
        }

        public function info(): ResolveInfo
        {
            return $this->node->info();
        }

        /** @return array<string,mixed> */
        public function associationDirective(): array
        {
            return $this->node->associationDirective();
        }

        /** @return array<string,mixed> */
        public function parentId(): array
        {
            return $this->node->parentId();
        }
    };
}
