<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugin;

use Wedrix\Watchtower\Plugin;

use function Wedrix\Watchtower\string\tableize;

final class SelectorPlugin implements Plugin
{
    private readonly string $type;

    private readonly string $name;

    private readonly string $namespace;

    private readonly string $template;

    private readonly string $callback;

    public function __construct(
        private readonly string $parentNodeType,
        private readonly string $fieldName
    )
    {
        $this->type = (function (): string {
            return 'selector';
        })();

        $this->name = (function (): string {
            return "apply_".tableize($this->parentNodeType)
                    ."_".tableize($this->fieldName)."_selector";
        })();

        $this->namespace = (function (): string {
            return __NAMESPACE__."\\SelectorPlugin";
        })();

        $this->template = (function (): string {
            return <<<EOD
            <?php

            declare(strict_types=1);

            namespace {$this->namespace};

            use Wedrix\Watchtower\Resolver\Node;
            use Wedrix\Watchtower\Resolver\QueryBuilder;

            function {$this->name}(
                QueryBuilder \$queryBuilder,
                Node \$node
            ): void
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