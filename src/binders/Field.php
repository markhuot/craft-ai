<?php

namespace markhuot\craftai\binders;

use Craft;
use craft\base\FieldInterface;
use craft\helpers\StringHelper;

class Field implements Binder
{
    public function __construct(
        public readonly bool $uidOnly = false,
    ) {}

    public function bind(mixed $value, array $arguments): ?FieldInterface
    {
        if ($value instanceof FieldInterface) {
            return $value;
        }

        if ($this->uidOnly) {
            if (! is_string($value) || ! StringHelper::isUUID($value)) {
                return null;
            }

            return Craft::$app->fields->getFieldByUid($value);
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
