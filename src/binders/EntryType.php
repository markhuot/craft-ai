<?php

namespace markhuot\craftai\binders;

use Craft;

class EntryType implements Binder
{
    public function __construct(
        public readonly ?string $inSection = null,
    ) {}

    public function bind(mixed $value, array $arguments): ?\craft\models\EntryType
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $isId = is_int($value) || ctype_digit($value);

        if ($this->inSection !== null) {
            $section = (new Section())->bind($arguments[$this->inSection] ?? null, $arguments);
            if (! $section instanceof \craft\models\Section) {
                return null;
            }

            foreach ($section->getEntryTypes() as $candidate) {
                if ($isId ? $candidate->id === (int) $value : $candidate->handle === $value) {
                    return $candidate;
                }
            }

            return null;
        }

        if (is_int($value) || ctype_digit($value)) {
            return Craft::$app->entries->getEntryTypeById((int) $value);
        }

        return Craft::$app->entries->getEntryTypeByHandle($value);
    }
}
