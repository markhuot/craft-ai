<?php

namespace markhuot\craftai\binders;

use craft\elements\Entry as EntryElement;

class Draft implements Binder
{
    public function bind(mixed $value, array $arguments): ?EntryElement
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $draft = EntryElement::find()->draftId((int) $value)->status(null)->one();

        return $draft instanceof EntryElement ? $draft : null;
    }
}
