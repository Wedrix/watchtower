<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Wedrix\Watchtower\ScalarTypeDefinition;

use function Wedrix\Watchtower\classify;

trait GenericScalarTypeDefinition
{
    private string $namespace;

    private string $template;

    public function __construct(
        private string $typeName
    )
    {
        $this->namespace = __NAMESPACE__.'\\'.classify($this->typeName).'TypeDefinition';

        $this->template = <<<EOD
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

function GenericScalarTypeDefinition(
    string $typeName
): ScalarTypeDefinition {
    /**
     * @var array<string,ScalarTypeDefinition>
     */
    static $instances = [];

    return $instances[$typeName] ??= new class(
        typeName: $typeName,
    ) implements ScalarTypeDefinition {
        use GenericScalarTypeDefinition;
    };
}