<?php

namespace markhuot\craftai\events;

use craft\base\FieldInterface;
use yii\base\Event;

/**
 * Fired from `UpsertField::summarize()` so listeners can attach advisory
 * `_notes` strings to the field payload returned by `GetFields` and
 * `UpsertField`. Each listener inspects `$field` (typically via instanceof)
 * and appends to `$notes`. Subscribers should *append* — they do not need to
 * read what other subscribers added. All non-empty notes are joined with
 * "\n\n" into the final payload's `_notes` key.
 */
class DefineFieldNotesEvent extends Event
{
    public FieldInterface $field;

    /** @var string[] */
    public array $notes = [];
}
