<?php

namespace markhuot\craftai\actions;

use craft\base\Element;
use craft\helpers\Search;
use Illuminate\Support\Collection;

class GetElementKeywords
{
    /**
     * @return Collection<array<array-key, string>>
     */
    public function handle(Element $element): Collection
    {
        $attributeKeywords = [];
        $attributes = $element::searchableAttributes();
        if  ($element::hasTitles()) {
            $attributes[] = 'title';
        }
        foreach ($attributes as $attribute) {
            $attributeKeywords[$attribute] = $element->getSearchKeywords($attribute);
        }

        $fieldKeywords = collect($element->getFieldLayout()?->getCustomFields() ?? [])
            ->filter(fn ($field) => $field->searchable)
            ->mapWithKeys(fn ($field) => [
                $field->handle => Search::normalizeKeywords($field->getSearchKeywords($element->getFieldValue($field->handle), $element))
            ])
            ->toArray();

        return collect(array_merge($attributeKeywords, $fieldKeywords))
            ->filter(fn ($value) => !empty($value) && mb_strlen($value) > 3);
    }
}
