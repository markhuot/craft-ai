<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that a string value matches the handle of an existing Craft site.
 */
class ExistingSite extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $handle = $model->{$attribute};

        if (! is_string($handle)) {
            $this->addError($model, $attribute, '{attribute} must be a string.');

            return;
        }

        if (Craft::$app->sites->getSiteByHandle($handle) === null) {
            $this->addError($model, $attribute, "No site found with handle \"{$handle}\".");
        }
    }
}
