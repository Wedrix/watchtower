<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins;

use Wedrix\Watchtower\Plugin;

use function Wedrix\Watchtower\string\tableize;

final class MutationPlugin implements Plugin
{
    private readonly string $type;

    private readonly string $name;

    private readonly string $namespace;

    private readonly string $template;

    private readonly string $callback;

    public function __construct(
        private readonly string $fieldName
    )
    {
        $this->type = (function (): string {
            return 'mutation';
        })();

        $this->name = (function (): string {
            return "call_".tableize($this->fieldName)."_mutation";
        })();

        $this->namespace = (function (): string {
            return __NAMESPACE__."\\Mutations";
        })();

        $this->template = (function (): string {
            return <<<EOD
            <?php

            declare(strict_types=1);
            
            namespace {$this->namespace};
            
            use Wedrix\Watchtower\Resolver\Node;
            
            /**
             * @param array<string,mixed> \$context
             * 
             * @return string|int|float|bool|null|array<mixed>
             */
            function {$this->name}(
                Node \$node, 
                array \$context
            ): string|int|float|bool|null|array
            {
            }
            EOD;
        })();

        $this->callback = (function (): string {
            return $this->namespace."\\".$this->name;
        })();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function callback(): callable
    {
        return is_callable($this->callback)
            ? $this->callback
            : throw new \Exception("Invalid callable string '{$this->callback}'.");
    }

    public function template(): string
    {
        return $this->template;
    }
}