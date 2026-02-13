<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

trait RootConstraintPlugin
{
    private string $type;

    private string $name;

    private string $namespace;

    private string $template;

    private string $callback;

    public function __construct()
    {
        $this->type = 'constraint';

        $this->name = 'apply_constraint';

        $this->namespace = __NAMESPACE__.'\\ConstraintPlugin';

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

function RootConstraintPlugin(): Plugin
{
    static $instance;

    return $instance ??= new class implements Plugin
    {
        use RootConstraintPlugin;
    };
}
