<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that every value in a list identifies an existing Craft entry
 * type, by handle (string) or ID (integer / numeric string). Empty lists are
 * rejected so callers cannot save a section without at least one entry type.
 */
class ExistingEntryTypes extends Validator implements ValidatesUnboundParameters
{
    public $skipOnEmpty = false;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_array($value) || $value === []) {
            $this->addError($model, $attribute, '{attribute} must be a non-empty list of entry type handles or IDs.');

            return;
        }

        foreach ($value as $item) {
            if (! is_int($item) && ! is_string($item)) {
                $this->addError($model, $attribute, '{attribute} entries must be string handles or numeric IDs.');

                return;
            }

            $found = is_int($item) || ctype_digit($item)
                ? Craft::$app->entries->getEntryTypeById((int) $item)
                : Craft::$app->entries->getEntryTypeByHandle($item);

            if ($found === null) {
                $this->addError($model, $attribute, "No entry type found matching \"{$item}\".");

                return;
            }
        }
    }
}
