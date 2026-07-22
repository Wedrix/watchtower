<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

trait RootNodeAuthorizorPlugin
{
    private string $type;

    private string $name;

    private string $namespace;

    private string $template;

    private string $callback;

    public function __construct()
    {
        $this->type = 'node_authorizor';

        $this->name = 'authorize_node';

        $this->namespace = __NAMESPACE__.'\\NodeAuthorizorPlugin';

        $this->template = <<<EOD
        <?php

        declare(strict_types=1);

        namespace {$this->namespace};

        use Wedrix\Watchtower\Resolver\Node;

        function {$this->name}(
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
            : throw new InvalidCallablePluginException("Invalid callable string '{$this->callback}'.");
    }

    public function template(): string
    {
        return $this->template;
    }
}

function RootNodeAuthorizorPlugin(): Plugin
{
    static $instance;

    return $instance ??= new class implements Plugin
    {
        use RootNodeAuthorizorPlugin;
    };
}
