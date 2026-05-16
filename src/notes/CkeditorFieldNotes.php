<?php

namespace markhuot\craftai\notes;

use markhuot\craftai\events\DefineFieldNotesEvent;

/**
 * Adds advisory `_notes` to CKEditor field payloads so the agent understands
 * that entries listed in `settings.entryTypes` are nested (owned by the host
 * entry, not section entries) and knows the embed format.
 */
class CkeditorFieldNotes
{
    public function __invoke(DefineFieldNotesEvent $event): void
    {
        if (! $event->field instanceof \craft\ckeditor\Field) {
            return;
        }

        $event->notes[] = 'CKEditor field. `settings.entryTypes` lists entry types that can be embedded inline as *nested* entries — these are owned by the host entry (sectionId is null) and do not live in a section. In the field HTML, reference each nested entry as `<craft-entry data-entry-id="<id>"></craft-entry>` (the HTML purifier only whitelists `data-entry-id` and `data-site-id`, both numeric). The cleanest way to create a host entry plus its nested components in one upsert_entry call is the structured payload — pass the field as `{"html": "...<craft-entry data-entry-id=\"new1\"></craft-entry>...", "entries": {"new1": {"type": "<entryTypeHandle>", "title": "...", "fields": {...}}}}` and the tool creates the nested entries with the right ownership and substitutes the placeholder IDs for you. See `upsert_entry`\'s description for the full example.';
    }
}
