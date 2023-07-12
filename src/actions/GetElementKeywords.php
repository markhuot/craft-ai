<?php

namespace markhuot\craftai\actions;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Search;
use Illuminate\Support\Collection;
use function markhuot\openai\helpers\throw_if;

class GetElementKeywords
{
    /**
     * @return Collection<array-key, string>
     */
    public function handle(ElementInterface $element): Collection
    {
        return collect()
            ->merge($this->getAttributeKeywords($element))
            ->merge($this->getFieldKeywords($element))
            ->filter(fn ($value) => ! empty($value) && mb_strlen($value) > 3); // @phpstan-ignore-line for some reason mb_strlen is typing as int<0, 1> so it thinks 3 is always greater. It's not realizing that this could be any length string.
    }

    /**
     * @return Collection<array-key, string>
     */
    protected function getAttributeKeywords(ElementInterface $element): Collection
    {
        return collect($element::searchableAttributes())
            ->when($element::hasTitles(), fn ($c) => $c->push('title'))
            ->mapWithKeys(fn ($attribute) => [
                $attribute => $element->getSearchKeywords($attribute),
            ]);
    }

    /**
     * @return Collection<array-key, string>
     */
    protected function getFieldKeywords(ElementInterface $element): Collection
    {
        /** @var Field[] $customFields */
        $customFields = $element->getFieldLayout()?->getCustomFields() ?? [];

        return collect($customFields)
            ->filter(fn ($field) => $field->searchable)
            ->mapWithKeys(function ($field) use ($element) {
                $handle = $field->handle;
                throw_if(! $handle, 'Field does not have a handle');

                return [
                    $handle => Search::normalizeKeywords($field->getSearchKeywords($element->getFieldValue($handle), $element)),
                ];
            });
    }
}
