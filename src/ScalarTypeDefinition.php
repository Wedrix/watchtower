<?php

namespace Wedrix\Watchtower;

interface ScalarTypeDefinition
{
    public function typeName(): string;

    public function namespace(): string;

    public function template(): string;
}