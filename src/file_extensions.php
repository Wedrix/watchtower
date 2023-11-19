<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

/**
 * @see https://www.php.net/manual/en/function.file-put-contents.php#123657
 * 
 * @param resource $context
 */
function file_force_put_contents(
    string $filename,
    mixed $data,
    int $flags = 0,
    $context = null
): int|false
{
    $parts = \explode('/', $filename);
    \array_pop($parts);
    $dir = \implode('/', $parts);

    if (!\is_dir($dir)) {
        \mkdir($dir, 0777, true);
    }

    return \file_put_contents($filename, $data, $flags);
}