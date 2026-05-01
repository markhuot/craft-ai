<?php

namespace markhuot\craftai\binders;

use Craft;

class SectionBinder implements Binder
{
    public function sourceSchema(): array
    {
        return ['type' => 'string'];
    }

    public function bind(mixed $value, array $arguments): mixed
    {
        if (! is_string($value)) {
            return null;
        }

        return Craft::$app->entries->getSectionByHandle($value);
    }
}
