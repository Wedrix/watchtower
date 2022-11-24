<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use function Wedrix\Watchtower\string\classify;

final class UngeneratedScalarTypeDefinition implements ScalarTypeDefinition
{
    private readonly string $namespace;

    private readonly string $template;

    public function __construct(
        private readonly string $typeName
    )
    {
        $this->namespace = (function (): string {
            return __NAMESPACE__."\\ScalarTypeDefinitions\\".classify($this->typeName)."TypeDefinition";
        })();

        $this->template = (function (): string {
            return <<<EOD
            <?php

            declare(strict_types=1);
            
            namespace {$this->namespace};
            
            /**
             * Serializes an internal value to include in a response.
             */
            function serialize(mixed \$value): string
            {
            }
            
            /**
             * Parses an externally provided value (query variable) to use as an input
             */
            function parseValue(string \$value): mixed
            {
            }
            
            /**
             * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
             * 
             * @param array<string,mixed>|null \$variables
             * @return mixed
             */
            function parseLiteral(\GraphQL\Language\AST\Node \$value, ?array \$variables = null): mixed
            {
            }
            EOD;
        })();
    }

    public function typeName(): string
    {
        return $this->typeName;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function template(): string
    {
        return $this->template;
    }
}