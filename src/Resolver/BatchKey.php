<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Resolver;

interface BatchKey
{
    public function value(): string;
}

function BatchKey(
    Node $node,
): BatchKey
{ 
    return new class($node) implements BatchKey
    {
        private string $value;
        
        public function __construct(
            private Node $node
        )
        {
            $this->value = (function (): string {
                $args = $this->node->args(); 
                
                // Recursively sort arrays and associative arrays
                $sortArgs = function ($value) use (&$sortArgs) {
                    if (\is_array($value)) {
                        // Check if associative 
                        if (!\array_is_list($value)) {
                            \ksort($value); 
                        } else { 
                            \sort($value); 
                        }
                        
                        foreach ($value as &$v) {
                            $v = $sortArgs($v); 
                        }
                    }
                    
                    return $value; 
                };
                
                $sortedArgs = $sortArgs($args);
                
                return $this->node->unwrappedParentType() . '|' . $this->node->name() . '|' . \json_encode($sortedArgs);
            })();
        }
        
        public function value(): string {
            return $this->value;
        }
    };
}