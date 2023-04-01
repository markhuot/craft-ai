<?php

namespace markhuot\craftai\actions;

use craft\base\ElementInterface;
use craft\helpers\Search;
use Illuminate\Support\Collection;

class GetElementKeywords
{
    /**
     * @return Collection<array<array-key, string>>
     */
    public function handle(ElementInterface $element): Collection
    {
        return collect()
            ->merge($this->getAttributeKeywords($element))
            ->merge($this->getFieldKeywords($element))
            ->filter(fn ($value) => ! empty($value) && mb_strlen($value) > 3);
    }

    protected function getAttributeKeywords(ElementInterface $element)
    {
        return collect($element::searchableAttributes())
            ->when($element::hasTitles(), fn ($c) => $c->push('title'))
            ->mapWithKeys(fn ($attribute) => [
                $attribute => $element->getSearchKeywords($attribute),
            ]);
    }

    protected function getFieldKeywords(ElementInterface $element)
    {
        return collect($element->getFieldLayout()?->getCustomFields() ?? [])
            ->filter(fn ($field) => $field->searchable)
            ->mapWithKeys(fn ($field) => [
                $field->handle => Search::normalizeKeywords($field->getSearchKeywords($element->getFieldValue($field->handle), $element)),
            ]);
    }
}
