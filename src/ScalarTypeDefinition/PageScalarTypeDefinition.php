<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\ScalarTypeDefinition;

use Wedrix\Watchtower\ScalarTypeDefinition;

final class PageScalarTypeDefinition implements ScalarTypeDefinition
{
    private readonly GenericScalarTypeDefinition $scalarTypeDefinition;

    private readonly string $template;

    public function __construct()
    {
        $this->scalarTypeDefinition = new GenericScalarTypeDefinition(
            typeName: 'Page'
        );

        $this->template = <<<EOD
        <?php

        declare(strict_types=1);
        
        namespace Wedrix\Watchtower\ScalarTypeDefinition\PageTypeDefinition;
        
        use GraphQL\Error\Error;
        use GraphQL\Language\AST\IntValueNode;
        use GraphQL\Utils\Utils;
        
        /**
         * Serializes an internal value to include in a response.
         *
         * @param int \$value
         * @return int
         */
        function serialize(\$value)
        {
            return \$value;
        }
        
        /**
         * Parses an externally provided value (query variable) to use as an input
         *
         * @param int \$value
         * @return int
         * @throws Error
         */
        function parseValue(\$value)
        {
            if ((\$value < 1)) {
                throw new Error(
                    message: "Cannot represent the following value as Page: " . Utils::printSafeJson(\$value)
                );
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
         * @param \GraphQL\Language\AST\Node \$value
         * @param array<string,mixed>|null \$variables
         * @return int
         * @throws Error
         */
        function parseLiteral(\$value, ?array \$variables = null)
        {
            if (!\$value instanceof IntValueNode) {
                throw new Error(
                    message: "Query error: Can only parse ints got: \$value->kind",
                    nodes: \$value
                );
            }
        
            try {
                return parseValue((int) \$value->value);
            }
            catch (\Exception \$e) {
                throw new Error(
                    message: "Not a valid Page Type",
                    nodes: \$value,
                    previous: \$e
                );
            }
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