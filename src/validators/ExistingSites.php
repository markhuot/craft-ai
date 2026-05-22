<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that every value in a list identifies an existing Craft site,
 * by handle (string) or ID (integer / numeric string). Empty lists are
 * rejected so callers cannot enable a section against no sites. Null is
 * allowed so the parameter can be omitted on update.
 */
class ExistingSites extends Validator implements ValidatesUnboundParameters
{
    public $skipOnEmpty = false;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if ($value === null) {
            return;
        }

        if (! is_array($value) || $value === []) {
            $this->addError($model, $attribute, '{attribute} must be a non-empty list of site handles or IDs.');

            return;
        }

        foreach ($value as $item) {
            if (! is_int($item) && ! is_string($item)) {
                $this->addError($model, $attribute, '{attribute} entries must be string handles or numeric IDs.');

                return;
            }

            $found = is_int($item) || ctype_digit($item)
                ? Craft::$app->sites->getSiteById((int) $item)
                : Craft::$app->sites->getSiteByHandle($item);

            if ($found === null) {
                $this->addError($model, $attribute, "No site found matching \"{$item}\".");

                return;
            }
        }
    }
}
