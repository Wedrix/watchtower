<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Wedrix\Watchtower\Plugin;

trait RootAuthorizorPlugin
{
    private readonly string $type;

    private readonly string $name;

    private readonly string $namespace;

    private readonly string $template;

    private readonly string $callback;

    public function __construct()
    {
        $this->type = 'authorizor';

        $this->name = 'authorize_result';

        $this->namespace = __NAMESPACE__.'\\AuthorizorPlugin';

        $this->template = <<<EOD
        <?php

        declare(strict_types=1);

        namespace {$this->namespace};

        use Wedrix\Watchtower\Resolver\Node;
        use Wedrix\Watchtower\Resolver\Result;

        function {$this->name}(
            Result \$result,
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

function RootAuthorizorPlugin(): Plugin
{
    static $instance = new class() implements Plugin {
        use RootAuthorizorPlugin;
    };

    return $instance;
}