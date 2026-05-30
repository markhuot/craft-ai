<?php

namespace markhuot\craftai\listeners;

use craft\events\DefineElementEditorHtmlEvent;
use craft\helpers\Json;

/**
 * Emit a deterministic element-context blob into every element editor's
 * content HTML, so the front-end never has to guess which entry/draft is
 * on screen.
 *
 * THE PROBLEM THIS SOLVES: on a *draft* edit screen Craft sets the form's
 * hidden `elementId` input to the **canonical** id and renders **no**
 * `draftId` input at all (see `ElementsController::_prepareEditor`, which
 * uses `getCanonicalId()` for drafts/revisions). The true draftId lives
 * only inside the `Craft.ElementEditor` JS settings object. A front-end
 * that reads the form inputs therefore reports the canonical identity even
 * while you edit a draft — so comments anchored to the draft go invisible.
 *
 * Rather than reverse-engineer the editor's runtime state, we read the
 * actual element being edited *server-side* — where the draft/canonical
 * identity is unambiguous — and hand it to the client as JSON. This event
 * fires for full-page edits and slideouts alike, and re-fires when Craft
 * swaps editor content after a client-side draft switch, so the tag stays
 * in sync with whatever the editor is currently showing.
 *
 * The shape mirrors the `Craft.ElementEditor` settings the front-end also
 * understands (`elementId` / `canonicalId` / `draftId`), so the same
 * normalizer consumes either source.
 */
class InjectElementContext
{
    public function __invoke(DefineElementEditorHtmlEvent $event): void
    {
        $element = $event->element;

        // draftId / revisionId come from the draft/revision behaviors,
        // which are only attached when the element actually is one — gate
        // the reads so a canonical element doesn't trip Yii's unknown-
        // property __get.
        $isDraft = $element->getIsDraft();
        $isRevision = $element->getIsRevision();

        $context = [
            'elementId' => (int) $element->id,
            'canonicalId' => (int) $element->getCanonicalId(),
            'draftId' => $isDraft ? (int) $element->draftId : null,
            'revisionId' => $isRevision ? (int) $element->revisionId : null,
            'isDraft' => $isDraft,
            'isRevision' => $isRevision,
            'siteId' => (int) $element->siteId,
        ];

        // Json::htmlEncode escapes HTML-sensitive chars as \uXXXX so the
        // payload is safe to drop verbatim into a <script> body (script
        // text is not HTML-decoded, so we must NOT use Html::tag's content
        // encoding) and stays valid JSON. Same approach as the comments
        // overlay bootstrap.
        $json = Json::htmlEncode($context);

        $event->html .= "\n<script type=\"application/json\" data-craftai-element-context>{$json}</script>";
    }
}
