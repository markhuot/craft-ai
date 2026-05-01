<?php

namespace markhuot\craftai\binders;

use Craft;

class Section implements Binder
{
    public function bind(mixed $value, array $arguments): ?\craft\models\Section
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Craft::$app->entries->getSectionById((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        return Craft::$app->entries->getSectionByHandle($value);
    }
}
