<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\ScalarTypeDefinition;

use Wedrix\Watchtower\ScalarTypeDefinition;

final class DateTimeScalarTypeDefinition implements ScalarTypeDefinition
{
    private readonly GenericScalarTypeDefinition $scalarTypeDefinition;

    private readonly string $template;

    public function __construct()
    {
        $this->scalarTypeDefinition = new GenericScalarTypeDefinition(
            typeName: 'DateTime'
        );

        $this->template = <<<EOD
        <?php

        declare(strict_types=1);

        namespace Wedrix\Watchtower\ScalarTypeDefinition\DateTimeTypeDefinition;

        use GraphQL\Error\Error;
        use GraphQL\Language\AST\StringValueNode;
        use GraphQL\Utils\Utils;

        /**
         * Serializes an internal value to include in a response.
         *
         * @param \DateTime \$value
         * @return string
         */
        function serialize(\$value)
        {
            return \$value->format(\DateTime::ATOM);
        }

        /**
         * Parses an externally provided value (query variable) to use as an input
         *
         * @param string \$value
         * @return \DateTime
         */
        function parseValue(\$value)
        {
            try {
                return new \DateTime(\$value);
            }
            catch (\Exception \$e) {
                throw new Error(
                    message: "Cannot represent the following value as DateTime: " . Utils::printSafeJson(\$value),
                    previous: \$e
                );
            }
        }

        /**
         * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
         * 
         * E.g. 
         * {
         *   user(createdAt: "2021-01-24T05:16:41+00:00") 
         * }
         *
         * @param \GraphQL\Language\AST\Node \$value
         * @param array<string,mixed>|null \$variables
         * @return \DateTime
         * @throws Error
         */
        function parseLiteral(\$value, ?array \$variables = null)
        {
            if (!\$value instanceof StringValueNode) {
                throw new Error(
                    message: "Query error: Can only parse strings got: \$value->kind",
                    nodes: \$value
                );
            }

            try {
                return parseValue(\$value->value);
            }
            catch (\Exception \$e) {
                throw new Error(
                    message: "Not a valid DateTime Type", 
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