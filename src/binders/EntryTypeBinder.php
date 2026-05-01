<?php

namespace markhuot\craftai\binders;

use Craft;

class EntryTypeBinder implements Binder
{
    public function __construct(
        public readonly ?string $inSection = null,
    ) {}

    public function sourceSchema(): array
    {
        return ['type' => 'string'];
    }

    public function bind(mixed $value, array $arguments): mixed
    {
        if (! is_string($value)) {
            return null;
        }

        if ($this->inSection !== null) {
            $sectionHandle = $arguments[$this->inSection] ?? null;
            if (! is_string($sectionHandle)) {
                return null;
            }

            $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
            if ($section === null) {
                return null;
            }

            foreach ($section->getEntryTypes() as $candidate) {
                if ($candidate->handle === $value) {
                    return $candidate;
                }
            }

            return null;
        }

        return Craft::$app->entries->getEntryTypeByHandle($value);
    }
}
