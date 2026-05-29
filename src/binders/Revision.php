<?php

namespace markhuot\craftai\binders;

use craft\elements\Entry as EntryElement;

/**
 * Binds a revision's element id to the loaded revision {@see EntryElement}.
 * Revisions are addressed by element id (see {@see \markhuot\craftai\validators\ExistingRevision}).
 */
class Revision implements Binder
{
    public function bind(mixed $value, array $arguments): ?EntryElement
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $revision = EntryElement::find()->revisions(true)->id((int) $value)->status(null)->one();

        return $revision instanceof EntryElement ? $revision : null;
    }
}
