<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

trait CursorScalarTypeDefinition
{
    private ScalarTypeDefinition $scalarTypeDefinition;

    private string $template;

    public function __construct()
    {
        $this->scalarTypeDefinition = GenericScalarTypeDefinition(
            typeName: 'Cursor'
        );

        $this->template = <<<'EOD'
        <?php

        declare(strict_types=1);

        namespace Wedrix\Watchtower\CursorTypeDefinition;

        use GraphQL\Language\AST\StringValueNode;

        /**
         * Serializes an internal value to include in a response.
         *
         * @param array<string,mixed>|string $value
         */
        function serialize(
            array|string $value
        ): string
        {
            if (\is_string($value)) {
                return $value;
            }

            return \base64_encode(\json_encode($value, \JSON_THROW_ON_ERROR));
        }

        /**
         * Parses an externally provided value (query variable) to use as an input.
         *
         * @return array<string,mixed>
         */
        function parseValue(
            string $value
        ): array
        {
            $json = \base64_decode($value, true);

            if ($json === false) {
                throw new \Wedrix\Watchtower\InvalidValueCursorScalarTypeDefinitionException('Invalid Cursor value!');
            }

            $decoded = \json_decode($json, true);

            if (! \is_array($decoded)) {
                throw new \Wedrix\Watchtower\InvalidValueCursorScalarTypeDefinitionException('Invalid Cursor value!');
            }

            return $decoded;
        }

        /**
         * Parses an externally provided literal value (hardcoded in GraphQL query) to use as an input.
         *
         * @param array<string,mixed>|null $variables
         * @return array<string,mixed>
         */
        function parseLiteral(
            StringValueNode $value,
            ?array $variables = null
        ): array
        {
            return parseValue($value->value);
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

function CursorScalarTypeDefinition(): ScalarTypeDefinition
{
    static $instance;

    return $instance ??= new class implements ScalarTypeDefinition
    {
        use CursorScalarTypeDefinition;
    };
}
