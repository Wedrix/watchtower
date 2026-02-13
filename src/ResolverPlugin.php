<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

trait ResolverPlugin
{
    private string $type;

    private string $name;

    private string $namespace;

    private string $template;

    private string $callback;

    public function __construct(
        private string $nodeType,
        private string $fieldName
    ) {
        $this->type = 'resolver';

        $this->name = 'resolve_'.tableize($this->nodeType)
        .'_'.tableize($this->fieldName).'_field';

        $this->namespace = __NAMESPACE__.'\\ResolverPlugin';

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

function ResolverPlugin(
    string $nodeType,
    string $fieldName
): Plugin {
    /**
     * @var array<string,array<string,Plugin>>
     */
    static $instances = [];

    return $instances[$nodeType][$fieldName] ??= new class(nodeType: $nodeType, fieldName: $fieldName) implements Plugin
    {
        use ResolverPlugin;
    };
}
