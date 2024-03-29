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
        
        use GraphQL\Language\AST\StringValueNode;
        
        /**
         * Serializes an internal value to include in a response.
         */
        function serialize(
            \DateTimeImmutable \$value
        ): string
        {
            return \$value->format(\DateTimeImmutable::ATOM);
        }
        
        /**
         * Parses an externally provided value (query variable) to use as an input
         */
        function parseValue(
            string \$value
        ): \DateTimeImmutable
        {
            return \date_create_immutable(\$value) 
                ?: throw new \Exception('Invalid DateTime value!');
        }
        
        /**
         * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
         * 
         * E.g. 
         * {
         *   user(createdAt: "2021-01-24T05:16:41+00:00") 
         * }
         *
         * @param array<string,mixed>|null \$variables
         */
        function parseLiteral(
            StringValueNode \$value, 
            ?array \$variables = null
        ): \DateTimeImmutable
        {
            return parseValue(\$value->value);
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