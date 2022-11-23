<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use function Wedrix\Watchtower\string\pluralize;
use function Wedrix\Watchtower\string\singularize;

/**
 * @implements \IteratorAggregate<int,PluginInfo>
 */
final class Plugins implements \IteratorAggregate
{
    public function __construct(
        private readonly string $directory
    )
    {
        if (!is_dir($this->directory)) {
            throw new \Exception("Invalid plugins directory '{$this->directory}'. Kindly ensure it exists or create it.");
        }
    }

    public function contains(
        Plugin $plugin
    ): bool
    {
        return file_exists(
            $this->directory($plugin)
        );
    }

    public function directory(
        Plugin $plugin
    ): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR
                .pluralize($plugin->type()).\DIRECTORY_SEPARATOR
                .$plugin->name().".php";
    }

    public function add(
        Plugin $plugin
    ): void
    {
        if ($this->contains($plugin)) {
            throw new \Exception("The plugin '{$plugin->name()}' already exists.");
        }

        file_put_contents(
            filename: $this->directory($plugin),
            data: $plugin->template(),
        );
    }

    public function getIterator(): \Traversable
    {
        $pluginDirectories = new \RegexIterator(
            iterator: new \RecursiveIteratorIterator(
                iterator: new \RecursiveDirectoryIterator($this->directory)
            ), 
            pattern: '/.+\.php/i',
            mode: \RecursiveRegexIterator::GET_MATCH
        );

        foreach ($pluginDirectories as $pluginDirectory) {
            yield new PluginInfo(
                pluginDirectory: $pluginDirectory[0]
            );
        }
    }
}

final class PluginInfo
{
    private readonly string $name;

    private readonly string $type;

    public function __construct(
        private readonly string $pluginDirectory
    )
    {
        $this->name = (function (): string {
            $dirElements = explode(\DIRECTORY_SEPARATOR, $this->pluginDirectory);

            return explode('.php', $dirElements[count($dirElements) - 1])[0];
        })();

        $this->type = (function (): string {
            $dirElements = explode(\DIRECTORY_SEPARATOR, $this->pluginDirectory);

            return singularize($dirElements[count($dirElements) - 2]);
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
}