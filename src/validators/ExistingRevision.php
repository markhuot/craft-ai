<?php

namespace markhuot\craftai\validators;

use craft\elements\Entry;
use yii\validators\Validator;

/**
 * Validates that a value is the element id of an existing Craft entry revision.
 *
 * Revisions are addressed by their element id — the value
 * {@see \craft\services\Revisions::createRevision()} returns and that
 * {@see \markhuot\craftai\tools\GetRevisions} lists.
 */
class ExistingRevision extends Validator
{
    public $skipOnEmpty = true;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            $this->addError($model, $attribute, '{attribute} must be a numeric revision ID.');

            return;
        }

        $exists = Entry::find()->revisions(true)->id((int) $value)->status(null)->exists();

        if (! $exists) {
            $this->addError($model, $attribute, "No revision found with ID {$value}.");
        }
    }
}
