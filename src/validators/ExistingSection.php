<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that a string value matches the handle of an existing Craft section.
 */
class ExistingSection extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute)
    {
        $handle = $model->{$attribute};

        if (! is_string($handle)) {
            $this->addError($model, $attribute, '{attribute} must be a string.');

            return;
        }

        if (Craft::$app->entries->getSectionByHandle($handle) === null) {
            $this->addError($model, $attribute, "No section found with handle \"{$handle}\".");
        }
    }
}
