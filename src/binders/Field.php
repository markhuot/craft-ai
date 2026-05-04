<?php

namespace markhuot\craftai\binders;

use Craft;
use craft\base\FieldInterface;

class Field implements Binder
{
    public function bind(mixed $value, array $arguments): ?FieldInterface
    {
        if ($value instanceof FieldInterface) {
            return $value;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Craft::$app->fields->getFieldById((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        return Craft::$app->fields->getFieldByHandle($value)
            ?? Craft::$app->fields->getFieldByUid($value);
    }
}
