<?php

namespace markhuot\craftai\listeners;

use Craft;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

/**
 * Add a "Compare…" button to the entry-edit action buttons, alongside
 * Preview. EVENT_DEFINE_ADDITIONAL_BUTTONS is the supported Craft 5
 * extension point for that row — its HTML is appended next to Preview /
 * Create-a-draft (the old cp.entries.edit.* Twig hooks were removed when
 * the editor moved to CpScreen, and no JS is involved). Gated to saved
 * entries/drafts that actually have revisions (same condition Craft uses
 * for its own revision-notes field), and never shown on a revision view.
 * Opens the compare page with B pinned to "current" and A left for the
 * editor to pick.
 */
class DefineCompareButton
{
    public function __invoke(DefineHtmlEvent $event): void
    {
        $entry = $event->sender;
        if (! $entry instanceof Entry || $entry->getIsRevision() || ! $entry->hasRevisions()) {
            return;
        }
        $canonicalId = $entry->getCanonicalId();
        if ($canonicalId === null) {
            return;
        }

        $url = UrlHelper::cpUrl('ai/compare', ['entryId' => $canonicalId, 'b' => 'current']);
        $event->html .= Html::a(
            Craft::t('craft-ai', 'Compare…'),
            $url,
            ['class' => ['btn'], 'data' => ['craftai-compare-button' => true]],
        );
    }
}
