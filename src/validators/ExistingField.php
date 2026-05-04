<?php

namespace markhuot\craftai\validators;

use Craft;
use craft\base\FieldInterface;
use yii\validators\Validator;

/**
 * Validates that a value identifies an existing custom field, by handle
 * (string), UID (string), or ID (integer / numeric string). After binding,
 * also asserts the resolved field has a non-null ID.
 */
class ExistingField extends Validator implements ValidatesUnboundParameters, ValidatesBoundParameters
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if ($value instanceof FieldInterface) {
            if ($value->id === null) {
                $this->addError($model, $attribute, '{attribute} is missing an ID.');
            }

            return;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            if (Craft::$app->fields->getFieldById((int) $value) === null) {
                $this->addError($model, $attribute, "No field found with ID {$value}.");
            }

            return;
        }

        if (! is_string($value)) {
            $this->addError($model, $attribute, '{attribute} must be a string handle/UID or numeric ID.');

            return;
        }

        $found = Craft::$app->fields->getFieldByHandle($value)
            ?? Craft::$app->fields->getFieldByUid($value);

        if ($found === null) {
            $this->addError($model, $attribute, "No field found matching \"{$value}\".");
        }
    }
}
