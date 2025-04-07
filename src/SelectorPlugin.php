<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Wedrix\Watchtower\Plugin;

use function Wedrix\Watchtower\tableize;

trait SelectorPlugin
{
    private string $type;

    private string $name;

    private string $namespace;

    private string $template;

    private string $callback;

    public function __construct(
        private string $nodeType,
        private string $fieldName
    )
    {
        $this->type = 'selector';

        $this->name = 'apply_'.tableize($this->nodeType)
        .'_'.tableize($this->fieldName).'_selector';

        $this->namespace = __NAMESPACE__.'\\SelectorPlugin';

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

function SelectorPlugin(
    string $nodeType,
    string $fieldName
): Plugin
{
    /**
     * @var array<string,array<string,Plugin>>
     */
    static $instances = [];

    return $instances[$nodeType][$fieldName] ??= new class(
        nodeType: $nodeType,
        fieldName: $fieldName
    ) implements Plugin {
        use SelectorPlugin;
    };
}