<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\validators\ExistingEntry;

/**
 * Retrieve a single content entry by its ID. Returns full entry details
 * including all custom field values, or an error if the entry is not found.
 */
class GetEntry extends Tool
{
    /**
     * @return array<array-key, mixed>
     */
    public function __invoke(
        #[Description('The entry ID to look up')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int $id,
    ): array {
        if (! $id instanceof Entry) {
            throw new \LogicException('Entry was not bound before invocation.');
        }

        return $id->toArray();
    }
}
