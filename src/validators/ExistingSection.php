<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that a value identifies an existing Craft section, by handle (string)
 * or ID (integer / numeric string).
 */
class ExistingSection extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute)
    {
        $value = $model->{$attribute};

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            if (Craft::$app->entries->getSectionById((int) $value) === null) {
                $this->addError($model, $attribute, "No section found with ID {$value}.");
            }

            return;
        }

        if (! is_string($value)) {
            $this->addError($model, $attribute, '{attribute} must be a string handle or numeric ID.');

            return;
        }

        if (Craft::$app->entries->getSectionByHandle($value) === null) {
            $this->addError($model, $attribute, "No section found with handle \"{$value}\".");
        }
    }
}
