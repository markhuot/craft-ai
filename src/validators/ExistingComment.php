<?php

namespace markhuot\craftai\validators;

use markhuot\craftai\records\CommentRecord;
use yii\validators\Validator;

/**
 * Validates that a value is the ID of an existing review comment row.
 */
class ExistingComment extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->addError($model, $attribute, '{attribute} must be a numeric comment ID.');

            return;
        }

        $exists = CommentRecord::find()->where(['id' => (int) $value])->exists();

        if (! $exists) {
            $this->addError($model, $attribute, "No comment found with ID {$value}.");
        }
    }
}
