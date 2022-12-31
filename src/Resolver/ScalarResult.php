<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

use Wedrix\Watchtower\Plugins;
use Wedrix\Watchtower\Plugin\ResolverPlugin;

final class ScalarResult implements Result
{
    private readonly bool $isWorkable;

    private readonly mixed $output;

    public function __construct(
        private readonly Node $node,
        private readonly EntityManager $entityManager,
        private readonly Plugins $plugins
    )
    {
        $this->isWorkable = (function (): bool {
            return !$this->plugins
                        ->contains(
                            new ResolverPlugin(
                                parentNodeType: $this->node->unwrappedParentType(),
                                fieldName: $this->node->name()
                            )
                        )
                        && (
                            $this->node->isALeaf()
                                || (
                                    isset($this->node->root()[$this->node->name()])
                                )
                                || (
                                    !$this->node->isTopLevel()
                                        && $this->entityManager
                                                ->hasEntity(
                                                    name: $entityName = $this->node->unwrappedParentType()
                                                )
                                        && $this->entityManager
                                                ->findEntity(
                                                    name: $entityName
                                                )
                                                ->hasEmbeddedField(
                                                    fieldName: $this->node->name()
                                                )
                                )
                        );
        })();

        $this->output = (function (): mixed {
            if ($this->isWorkable) {
                $fieldName = $this->node->name();
        
                $root = $this->node->root();
        
                if (array_key_exists($fieldName, $root)) {
                    return $root[$fieldName];
                }
        
                $embeddedFields = array_filter(array_keys($root), function ($field) use ($fieldName) {
                    return str_starts_with($field, "$fieldName.");
                });
        
                if (sizeof($embeddedFields) > 0) {
                    $embeddedField = [];
        
                    foreach ($embeddedFields as $field) {
                        $embeddedFieldName = substr($field, strpos($field, ".") + 1);
        
                        $embeddedField[$embeddedFieldName] = $root[$field];
                    }
        
                    return $embeddedField;
                }
        
                throw new \Exception("Invalid root value. The field '$fieldName' is unset in the resolved root.");
            }

            return null;
        })();
    }

    public function output(): mixed
    {
        return $this->output;
    }

    public function isWorkable(): bool
    {
        return $this->isWorkable;
    }
}