<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugins;

use Wedrix\Watchtower\Plugin;

use function Wedrix\Watchtower\string\pluralize;
use function Wedrix\Watchtower\string\tableize;

final class FilterPlugin implements Plugin
{
    private readonly string $type;

    private readonly string $name;

    private readonly string $namespace;

    private readonly string $template;

    private readonly string $callback;

    public function __construct(
        private readonly string $parentNodeType,
        private readonly string $filterName
    )
    {
        $this->type = (function (): string {
            return 'filter';
        })();

        $this->name = (function (): string {
            return "apply_".tableize(pluralize($this->parentNodeType))
                    ."_".tableize($this->filterName)."_filter";
        })();

        $this->namespace = (function (): string {
            return __NAMESPACE__."\\Filters";
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