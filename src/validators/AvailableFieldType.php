<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that a value is the FQCN of an installed Craft field type. The
 * accepted set comes from `Craft::$app->fields->getAllFieldTypes()` at runtime,
 * so plugin-registered field types are picked up automatically.
 */
class AvailableFieldType extends Validator implements ValidatesUnboundParameters
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_string($value)) {
            $this->addError($model, $attribute, '{attribute} must be a field type class name.');

            return;
        }

        $available = Craft::$app->fields->getAllFieldTypes();

        if (! in_array($value, $available, true)) {
            $this->addError(
                $model,
                $attribute,
                "{attribute} \"{$value}\" is not an installed field type.",
            );
        }
    }
}
