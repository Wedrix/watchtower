<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use function Wedrix\Watchtower\pluralize;
use function Wedrix\Watchtower\tableize;

trait AuthorizorPlugin
{
    private readonly string $type;

    private readonly string $name;

    private readonly string $namespace;

    private readonly string $template;

    private readonly string $callback;

    public function __construct(
        private readonly string $nodeType,
        private readonly bool $isForCollections
    )
    {
        $this->type = 'authorizor';

        $this->name = 'authorize_'.tableize(
            $this->isForCollections
                ? pluralize($this->nodeType)
                : $this->nodeType
        ).'_result';

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

function AuthorizorPlugin(
    string $nodeType,
    bool $isForCollections
): Plugin
{
    /**
     * @var array<string,array<string,Plugin>>
     */
    static $instances = [];

    return $instances[$nodeType][$isForCollections ? 'true' : 'false'] ??= new class(
        nodeType: $nodeType, 
        isForCollections: $isForCollections
    ) implements Plugin {
        use AuthorizorPlugin;
    };
}