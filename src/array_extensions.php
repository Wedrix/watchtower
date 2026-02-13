<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

/**
 * @param  array<int|string,mixed>  $needles
 * @param  array<int|string,mixed>  $haystack
 */
function any_in_array(array $needles, array $haystack): bool
{
    foreach ($needles as $needle) {
        if (\in_array($needle, $haystack)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  array<int|string,mixed>  $needles
 * @param  array<int|string,mixed>  $haystack
 */
function all_in_array(array $needles, array $haystack): bool
{
    foreach ($needles as $needle) {
        if (! \in_array($needle, $haystack)) {
            return false;
        }
    }

    return true;
}
