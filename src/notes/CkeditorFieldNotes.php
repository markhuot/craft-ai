<?php

namespace markhuot\craftai\notes;

use markhuot\craftai\events\DefineFieldNotesEvent;

/**
 * Adds advisory `_notes` to CKEditor field payloads so the agent understands
 * that entries listed in `settings.entryTypes` are nested (owned by the host
 * entry, not section entries) and knows the embed format. Also surfaces the
 * span-comment workflow so feedback on a specific paragraph/sentence inside
 * a long-form field lands on the right range of prose instead of dotting
 * the whole field heading.
 */
class CkeditorFieldNotes
{
    public function __invoke(DefineFieldNotesEvent $event): void
    {
        if (! $event->field instanceof \craft\ckeditor\Field) {
            return;
        }

        $event->notes[] = 'CKEditor field. `settings.entryTypes` lists entry types that can be embedded inline as *nested* entries — these are owned by the host entry (sectionId is null) and do not live in a section. In the field HTML, reference each nested entry as `<craft-entry data-entry-id="<id>"></craft-entry>` (the HTML purifier only whitelists `data-entry-id` and `data-site-id`, both numeric). The cleanest way to create a host entry plus its nested components in one upsert_entry call is the structured payload — pass the field as `{"html": "...<craft-entry data-entry-id=\"new1\"></craft-entry>...", "entries": {"new1": {"type": "<entryTypeHandle>", "title": "...", "fields": {...}}}}` and the tool creates the nested entries with the right ownership and substitutes the placeholder IDs for you. See `upsert_entry`\'s description for the full example.';

        $event->notes[] = 'CKEditor field: supports pinning review comments to a specific span of text. When `leave_comment` feedback targets a particular paragraph, sentence, or phrase rather than the whole field, (1) generate a short `referenceId` (UUID recommended), (2) edit the field HTML via `upsert_entry`/`upsert_draft` to wrap the target range in `<span class="craft-ai-comment-mark" data-craft-ai-comment-id="<referenceId>">target text</span>`, then (3) call `leave_comment` with that same `referenceId` plus `fieldHandle`. The HTML purifier whitelists both `class` and `data-craft-ai-comment-id` on `<span>` so the marker survives save. The server strips the wrapper automatically when the comment is resolved.';
    }
}
