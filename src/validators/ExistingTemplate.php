<?php

namespace markhuot\craftai\validators;

use Craft;
use craft\web\View;
use yii\validators\Validator;

/**
 * Validates that a value resolves to an existing site (front-end) Twig
 * template, using the same lookup rules Craft applies at render time. Pass
 * either a path with extension (`blog/post.twig`) or the bare template name
 * (`blog/post`) — both are accepted.
 */
class ExistingTemplate extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_string($value) || $value === '') {
            $this->addError($model, $attribute, '{attribute} must be a non-empty template path.');

            return;
        }

        if (! Craft::$app->getView()->doesTemplateExist($value, View::TEMPLATE_MODE_SITE)) {
            $this->addError($model, $attribute, "No template found at \"{$value}\".");
        }
    }
}
