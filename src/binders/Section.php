<?php

namespace markhuot\craftai\binders;

use Craft;

class Section implements Binder
{
    public function bind(mixed $value, array $arguments): mixed
    {
        if (is_int($value) || ctype_digit($value)) {
            return Craft::$app->entries->getSectionById((int) $value);
        }

        return Craft::$app->entries->getSectionByHandle($value);
    }
}
