<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

/**
 * @implements \IteratorAggregate<int,PluginInfo>
 */
final class Plugins implements \IteratorAggregate
{
    public function __construct(
        private readonly string $directory,
        private readonly string $cacheDirectory,
        private readonly bool $optimize
    ){}

    public function contains(
        Plugin $plugin
    ): bool
    {
        static $filesCache;

        if ($this->optimize) {
            $filesCache ??= require $this->cacheDirectory.'/plugins.php';

            return \in_array($this->filePath($plugin), $filesCache);
        }

        return \is_file(
            $this->filePath($plugin)
        );
    }

    public function filePath(
        Plugin|PluginInfo $plugin
    ): string
    {
        return $this->directory.'/'.pluralize($plugin->type()).'/'.$plugin->name().'.php';
    }

    public function add(
        Plugin $plugin
    ): void
    {
        if ($this->contains($plugin)) {
            throw new \Exception("The plugin '{$plugin->name()}' already exists.");
        }

        file_force_put_contents(
            filename: $this->filePath($plugin),
            data: $plugin->template(),
        );
    }

    public function getIterator(): \Traversable
    {
        if (!\is_dir($this->directory)) {
            return new \EmptyIterator();
        }

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