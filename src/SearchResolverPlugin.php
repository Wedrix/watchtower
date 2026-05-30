<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

trait SearchResolverPlugin
{
    private string $type;

    private string $name;

    private string $namespace;

    private string $template;

    private string $callback;

    public function __construct(
        private string $nodeType
    ) {
        $this->type = 'search_resolver';

        $this->name = 'resolve_'.tableize(pluralize($this->nodeType)).'_search';

        $this->namespace = __NAMESPACE__.'\\SearchResolverPlugin';

        $this->template = <<<EOD
        <?php

        declare(strict_types=1);

        namespace {$this->namespace};

        use Wedrix\Watchtower\Resolver\Node;

        function {$this->name}(
            Node \$node
        ): mixed
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

function SearchResolverPlugin(
    string $nodeType
): Plugin {
    /**
     * @var array<string,Plugin>
     */
    static $instances = [];

    return $instances[$nodeType] ??= new class(nodeType: $nodeType) implements Plugin
    {
        use SearchResolverPlugin;
    };
}
