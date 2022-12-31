<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\Plugin;

use Wedrix\Watchtower\Plugin;

use function Wedrix\Watchtower\string\pluralize;
use function Wedrix\Watchtower\string\tableize;

final class AuthorizorPlugin implements Plugin
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
        $this->type = (function (): string {
            return 'authorizor';
        })();

        $this->name = (function (): string {
            return "authorize_".tableize(
                $this->isForCollections
                    ? pluralize($this->nodeType)
                    : $this->nodeType
            )."_result";
        })();

        $this->namespace = (function (): string {
            return __NAMESPACE__."\\AuthorizorPlugin";
        })();

        $this->template = (function (): string {
            return <<<EOD
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