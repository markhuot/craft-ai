<?php

namespace markhuot\craftai\validators;

use Craft;
use craft\models\EntryType;
use yii\validators\Validator;

/**
 * Validates that a value identifies an existing Craft entry type, by handle
 * (string) or ID (integer / numeric string). When `inSection` is set to a
 * sibling attribute name, the entry type must also belong to the section
 * identified by that attribute. After binding, also asserts the resolved
 * EntryType has a non-null ID.
 */
class ExistingEntryType extends Validator implements ValidatesUnboundParameters, ValidatesBoundParameters
{
    public $skipOnEmpty = true;

    public ?string $inSection = null;

    public function validateAttribute($model, $attribute): void
    {
        $value = $model->{$attribute};

        if ($value instanceof EntryType) {
            if ($value->id === null) {
                $this->addError($model, $attribute, '{attribute} is missing an ID.');
            }

            return;
        }

        if (! is_int($value) && ! is_string($value)) {
            $this->addError($model, $attribute, '{attribute} must be a string handle or numeric ID.');

            return;
        }

        $isId = is_int($value) || ctype_digit($value);

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
                sprintf(
                    'No entry type "%s" found in section "%s".',
                    $value,
                    is_scalar($section = $model->{$this->inSection}) ? $section : '',
                ),
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
