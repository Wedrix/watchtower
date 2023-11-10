<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

/**
 * @implements \IteratorAggregate<int,PluginInfo>
 */
final class Plugins implements \IteratorAggregate
{
    /**
     * @var array<string>
     */
    private readonly array $cachedFiles;

    public function __construct(
        private readonly string $directory,
        private readonly string $cacheDirectory,
        private readonly bool $optimize
    )
    {
        if (!\is_dir($this->directory)) {
            throw new \Exception("Invalid plugins directory '{$this->directory}'. Kindly ensure it exists or create it.");
        }

        if ($this->optimize) {
            $this->cachedFiles = require $this->cacheDirectory.\DIRECTORY_SEPARATOR."_plugins.php";
        }
    }

    public function contains(
        Plugin $plugin
    ): bool
    {
        if ($this->optimize) {
            return \in_array($this->filePath($plugin), $this->cachedFiles);
        }

        return \file_exists(
            $this->filePath($plugin)
        );
    }

    public function filePath(
        Plugin|PluginInfo $plugin
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

        \file_put_contents(
            filename: $this->filePath($plugin),
            data: $plugin->template(),
        );
    }

    public function getIterator(): \Traversable
    {
        $pluginFiles = new \RegexIterator(
            iterator: new \RecursiveIteratorIterator(
                iterator: new \RecursiveDirectoryIterator($this->directory)
            ), 
            pattern: '/.+\.php/i',
            mode: \RegexIterator::MATCH
        );

        foreach ($pluginFiles as $pluginFile) {
            yield new PluginInfo(
                pluginFile: $pluginFile
            );
        }
    }
}