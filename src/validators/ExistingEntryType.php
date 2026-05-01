<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that a string value matches the handle of an existing Craft entry
 * type. When `inSection` is set to another attribute name, the entry type must
 * also belong to the section identified by that sibling attribute's handle.
 */
class ExistingEntryType extends Validator
{
    public $skipOnEmpty = true;

    public ?string $inSection = null;

    public function validateAttribute($model, $attribute)
    {
        $handle = $model->{$attribute};

        if (! is_string($handle)) {
            $this->addError($model, $attribute, '{attribute} must be a string.');

            return;
        }

        if ($this->inSection === null) {
            if (Craft::$app->entries->getEntryTypeByHandle($handle) === null) {
                $this->addError($model, $attribute, "No entry type found with handle \"{$handle}\".");
            }

            return;
        }

        $sectionHandle = $model->{$this->inSection} ?? null;
        if (! is_string($sectionHandle)) {
            return;
        }

        $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
        if ($section === null) {
            return;
        }

        foreach ($section->getEntryTypes() as $candidate) {
            if ($candidate->handle === $handle) {
                return;
            }
        }

        $this->addError(
            $model,
            $attribute,
            "No entry type \"{$handle}\" found in section \"{$sectionHandle}\".",
        );
    }
}
