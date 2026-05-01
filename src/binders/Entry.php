<?php

namespace markhuot\craftai\binders;

use craft\elements\Entry as EntryElement;

class Entry implements Binder
{
    public function bind(mixed $value, array $arguments): ?EntryElement
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $entry = EntryElement::find()->id((int) $value)->status(null)->one();

        return $entry instanceof EntryElement ? $entry : null;
    }
}
