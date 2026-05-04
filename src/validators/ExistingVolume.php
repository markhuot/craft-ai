<?php

namespace markhuot\craftai\validators;

use Craft;
use craft\models\Volume;
use yii\validators\Validator;

/**
 * Validates that a value identifies an existing Craft asset volume, by handle
 * (string) or ID (integer / numeric string). After binding, also asserts the
 * resolved Volume has a non-null ID.
 */
class ExistingVolume extends Validator implements ValidatesUnboundParameters, ValidatesBoundParameters
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if ($value instanceof Volume) {
            if ($value->id === null) {
                $this->addError($model, $attribute, '{attribute} is missing an ID.');
            }

            return;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            if (Craft::$app->volumes->getVolumeById((int) $value) === null) {
                $this->addError($model, $attribute, "No volume found with ID {$value}.");
            }

            return;
        }

        if (! is_string($value)) {
            $this->addError($model, $attribute, '{attribute} must be a string handle or numeric ID.');

            return;
        }

        if (Craft::$app->volumes->getVolumeByHandle($value) === null) {
            $this->addError($model, $attribute, "No volume found with handle \"{$value}\".");
        }
    }
}
