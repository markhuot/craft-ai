<?php

namespace markhuot\craftai\validators;

use craft\elements\Entry;
use yii\validators\Validator;

/**
 * Validates that a value is the ID of an existing Craft entry, including
 * disabled and otherwise non-live entries.
 */
class ExistingEntry extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->addError($model, $attribute, '{attribute} must be a numeric ID.');

            return;
        }

        $exists = Entry::find()->id((int) $value)->status(null)->exists();

        if (! $exists) {
            $this->addError($model, $attribute, "No entry found with ID {$value}.");
        }
    }
}
