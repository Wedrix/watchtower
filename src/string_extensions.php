<?php

declare(strict_types=1);

namespace Wedrix\Watchtower;

use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;

function Inflector(): Inflector
{
    static $instance;

    return $instance ??= InflectorFactory::create()->build();
}

function singularize(
    string $word
): string {
    return Inflector()->singularize($word);
}

function pluralize(
    string $word
): string {
    return Inflector()->pluralize($word);
}

function tableize(
    string $word
): string {
    return Inflector()->tableize($word);
}

function classify(
    string $word
): string {
    return Inflector()->classify($word);
}

function camelize(
    string $word
): string {
    return Inflector()->camelize($word);
}
