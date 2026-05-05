<?php

namespace markhuot\craftai\validators;

use Craft;
use craft\base\FieldInterface;
use craft\helpers\StringHelper;
use yii\validators\Validator;

/**
 * Validates that a value identifies an existing custom field. By default
 * accepts a handle (string), UID (string), or ID (integer / numeric string).
 * Set `uidOnly = true` to restrict the value to UID format — used by
 * field-layout tools where UIDs are the universal identifier. After binding,
 * also asserts the resolved field has a non-null ID.
 */
class ExistingField extends Validator implements ValidatesUnboundParameters, ValidatesBoundParameters
{
    public $skipOnEmpty = true;

    public bool $uidOnly = false;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if ($value instanceof FieldInterface) {
            if ($value->id === null) {
                $this->addError($model, $attribute, '{attribute} is missing an ID.');
            }

            return;
        }

        if ($this->uidOnly) {
            if (! is_string($value)) {
                $this->addError($model, $attribute, '{attribute} must be a field UID.');

                return;
            }

            if (! StringHelper::isUUID($value)) {
                $this->addError($model, $attribute, '{attribute} must be a field UID (UUID format).');

                return;
            }

            if (Craft::$app->fields->getFieldByUid($value) === null) {
                $this->addError($model, $attribute, "No field found with UID \"{$value}\".");
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
