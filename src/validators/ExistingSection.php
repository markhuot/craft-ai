<?php

namespace markhuot\craftai\validators;

use Craft;
use craft\models\Section;
use yii\validators\Validator;

/**
 * Validates that a value identifies an existing Craft section, by handle (string)
 * or ID (integer / numeric string). After binding, also asserts the resolved
 * Section has a non-null ID.
 */
class ExistingSection extends Validator implements ValidatesUnboundParameters, ValidatesBoundParameters
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if ($value instanceof Section) {
            if ($value->id === null) {
                $this->addError($model, $attribute, '{attribute} is missing an ID.');
            }

            return;
        }

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
