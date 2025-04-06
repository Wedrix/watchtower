<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Wedrix\Watchtower\ScalarTypeDefinition;

trait PageScalarTypeDefinition
{
    private readonly ScalarTypeDefinition $scalarTypeDefinition;

    private readonly string $template;

    public function __construct()
    {
        $this->scalarTypeDefinition = GenericScalarTypeDefinition(
            typeName: 'Page'
        );

        $this->template = <<<EOD
        <?php

        declare(strict_types=1);
        
        namespace Wedrix\Watchtower\ScalarTypeDefinition\PageTypeDefinition;
        
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
            if ((\$value < 1)) {
                throw new \Exception('Invalid Page value!');
            }
        
            return \$value;
        }
        
        /**
         * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
         * 
         * E.g. 
         * {
         *   page: 1,
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

function PageScalarTypeDefinition(): ScalarTypeDefinition
{
    static $instance = new class() implements ScalarTypeDefinition {
        use PageScalarTypeDefinition;
    };

    return $instance;
}