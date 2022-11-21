<?php

declare(strict_types=1);

namespace Wedrix\Watchtower\string;

use Wedrix\Inflector\Inflector;

function singularize(
    string $word
): string
{
    return (new Inflector())->singularize($word);
}

function pluralize(
    string $word
): string
{
    return (new Inflector())->pluralize($word);
}

function tableize(
    string $word
): string
{
    return (new Inflector())->tableize($word);
}

function classify(
    string $word
): string
{
    return (new Inflector())->classify($word);
}