<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use function Wedrix\Watchtower\pluralize;
use function Wedrix\Watchtower\tableize;

trait FilterPlugin
{
    private string $type;

    private string $name;

    private string $namespace;

    private string $template;

    private string $callback;

    public function __construct(
        private string $nodeType,
        private string $filterName
    )
    {
        $this->type = 'filter';

        $this->name = 'apply_'.tableize(pluralize($this->nodeType))
        .'_'.tableize($this->filterName).'_filter';

        $this->namespace = __NAMESPACE__."\\FilterPlugin";

        $this->template = <<<EOD
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

        $this->callback = $this->namespace.'\\'.$this->name;
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
        return \is_callable($this->callback)
            ? $this->callback
            : throw new \Exception("Invalid callable string '{$this->callback}'.");
    }

    public function template(): string
    {
        return $this->template;
    }
}

function FilterPlugin(
    string $nodeType,
    string $filterName
): Plugin {
    /**
     * @var array<string,array<string,Plugin>>
     */
    static $instances = [];

    return $instances[$nodeType][$filterName] ??= new class(
        nodeType: $nodeType, 
        filterName: $filterName
    ) implements Plugin {
        use FilterPlugin;
    };
}