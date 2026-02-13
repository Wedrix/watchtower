<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

trait LimitScalarTypeDefinition
{
    private ScalarTypeDefinition $scalarTypeDefinition;

    private string $template;

    public function __construct()
    {
        $this->scalarTypeDefinition = GenericScalarTypeDefinition(
            typeName: 'Limit'
        );

        $this->template = <<<EOD
        <?php

        declare(strict_types=1);
        
        namespace Wedrix\Watchtower\LimitTypeDefinition;
        
        use GraphQL\Language\AST\IntValueNode;
        
        /**
         * Serializes an internal value to include in a response.
         */
        function serialize(
            int \$value
        ): int
        {
            return \$value;
        }
        
        /**
         * Parses an externally provided value (query variable) to use as an input
         */
        function parseValue(
            int \$value
        ): int
        {
            if ((\$value < 1) || (\$value > 100)) {
                throw new \Exception('Invalid Limit value!');
            }
        
            return \$value;
        }
        
        /**
         * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
         * 
         * E.g. 
         * {
         *   limit: 1,
         * }
         *
         * @param array<string,mixed>|null \$variables
         */
        function parseLiteral(
            IntValueNode \$value, 
            ?array \$variables = null
        ): int
        {
            return parseValue((int) \$value->value);
        }
        EOD;
    }

    public function typeName(): string
    {
        return $this->scalarTypeDefinition
            ->typeName();
    }

    public function namespace(): string
    {
        return $this->scalarTypeDefinition
            ->namespace();
    }

    public function template(): string
    {
        return $this->template;
    }
}

function LimitScalarTypeDefinition(): ScalarTypeDefinition
{
    static $instance;

    return $instance ??= new class implements ScalarTypeDefinition
    {
        use LimitScalarTypeDefinition;
    };
}
