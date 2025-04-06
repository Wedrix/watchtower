<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

interface PluginInfo
{
    public function name(): string;

    public function type(): string;
}

function PluginInfo(
    \SplFileInfo $pluginFile
): PluginInfo
{
    /**
     * @var \WeakMap<\SplFileInfo,PluginInfo>
     */
    static $instances = [];

    return $instances[$pluginFile] ??= new class(
        pluginFile: $pluginFile
    ) implements PluginInfo {
        private readonly string $name;
    
        private readonly string $type;
    
        public function __construct(
            \SplFileInfo $pluginFile
        )
        {
            $this->name = \explode('.php', $pluginFile->getBasename())[0];
    
            $this->type = singularize(\array_slice(\explode(\DIRECTORY_SEPARATOR, $pluginFile->getPath()), -1)[0]);
        }
    
        public function name(): string
        {
            return $this->name;
        }
    
        public function type(): string
        {
            return $this->type;
        }
    };
}