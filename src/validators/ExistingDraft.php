<?php

namespace markhuot\craftai\validators;

use craft\elements\Entry;
use yii\validators\Validator;

/**
 * Validates that a value is the draftId of an existing Craft draft.
 */
class ExistingDraft extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->addError($model, $attribute, '{attribute} must be a numeric draft ID.');

            return;
        }

        $exists = Entry::find()->draftId((int) $value)->status(null)->exists();

        if (! $exists) {
            $this->addError($model, $attribute, "No draft found with ID {$value}.");
        }
    }
}
