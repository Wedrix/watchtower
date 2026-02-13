<?php

namespace Wedrix\Watchtower;

interface Plugin
{
    public function name(): string;

    public function type(): string;

    public function callback(): callable;

    public function template(): string;
}
