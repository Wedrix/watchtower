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

/**
 * @param  array<int|string,mixed>  $array
 */
function array_is_list(array $array): bool
{
    if (\function_exists('array_is_list')) {
        return \array_is_list($array);
    }

    return $array === [] || \array_keys($array) === \range(0, \count($array) - 1);
}
