<?php

namespace markhuot\craftai\validators;

use craft\elements\Asset;
use yii\validators\Validator;

/**
 * Validates that a value is the ID of an existing Craft asset, including
 * disabled and otherwise non-live assets.
 */
class ExistingAsset extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->addError($model, $attribute, '{attribute} must be a numeric ID.');

            return;
        }

        $exists = Asset::find()->id((int) $value)->status(null)->exists();

        if (! $exists) {
            $this->addError($model, $attribute, "No asset found with ID {$value}.");
        }
    }
}
