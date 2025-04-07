<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Wedrix\Watchtower\Plugin;

use function Wedrix\Watchtower\tableize;

trait SubscriptionPlugin
{
    private string $type;

    private string $name;

    private string $namespace;

    private string $template;

    private string $callback;

    public function __construct(
        private string $fieldName
    )
    {
        $this->type = 'subscription';

        $this->name = 'call_'.tableize($this->fieldName).'_subscription';

        $this->namespace = __NAMESPACE__.'\\SubscriptionPlugin';

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

function SubscriptionPlugin(
    string $fieldName
): Plugin
{
    /**
     * @var array<string,Plugin>
     */
    static $instances = [];

    return $instances[$fieldName] ??= new class(
        fieldName: $fieldName
    ) implements Plugin {
        use SubscriptionPlugin;
    };
}