<?php

namespace markhuot\craftai\validators;

use Craft;
use yii\validators\Validator;

/**
 * Validates that a value identifies an existing Craft entry type, by handle
 * (string) or ID (integer / numeric string). When `inSection` is set to a
 * sibling attribute name, the entry type must also belong to the section
 * identified by that attribute.
 */
class ExistingEntryType extends Validator
{
    public $skipOnEmpty = true;

    public ?string $inSection = null;

    public function validateAttribute($model, $attribute)
    {
        $value = $model->{$attribute};
        $isId = is_int($value) || (is_string($value) && ctype_digit($value));

        if (! $isId && ! is_string($value)) {
            $this->addError($model, $attribute, '{attribute} must be a string handle or numeric ID.');

            return;
        }

        if ($this->inSection !== null) {
            $section = $this->resolveSection($model->{$this->inSection} ?? null);
            if ($section === null) {
                return; // sibling-section validator will report the failure
            }

            foreach ($section->getEntryTypes() as $candidate) {
                if ($isId ? $candidate->id === (int) $value : $candidate->handle === $value) {
                    return;
                }
            }

            $this->addError(
                $model,
                $attribute,
                "No entry type \"{$value}\" found in section \"{$model->{$this->inSection}}\".",
            );

            return;
        }

        $found = $isId
            ? Craft::$app->entries->getEntryTypeById((int) $value)
            : Craft::$app->entries->getEntryTypeByHandle($value);

        if ($found === null) {
            $this->addError($model, $attribute, "No entry type found matching \"{$value}\".");
        }
    }

    private function resolveSection(mixed $value): ?\craft\models\Section
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Craft::$app->entries->getSectionById((int) $value);
        }

        if (is_string($value)) {
            return Craft::$app->entries->getSectionByHandle($value);
        }

        return null;
    }
}
