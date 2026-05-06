<?php

namespace markhuot\craftai\helpers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;

/**
 * Builds tokenized preview URLs for drafts so callers (chiefly the
 * `get_draft` and `upsert_draft` tools) hand the agent a link that renders
 * the draft's unpublished changes instead of the canonical entry's live
 * content.
 */
class DraftPreview
{
    /**
     * Returns a preview URL with a `token` query param, or null if the
     * draft has no front-end URL (e.g. its section has no URI format).
     * Falls back to the untokenized URL if Craft was unable to issue a token.
     */
    public static function urlFor(Entry $draft): ?string
    {
        $url = $draft->getUrl();
        if ($url === null) {
            return null;
        }

        $userId = Craft::$app->getUser()->getId();

        $token = Craft::$app->getTokens()->createPreviewToken([
            'preview/preview',
            [
                'elementType' => Entry::class,
                'canonicalId' => (int) $draft->getCanonicalId(),
                'siteId' => (int) $draft->siteId,
                'draftId' => $draft->draftId !== null ? (int) $draft->draftId : null,
                'revisionId' => null,
                'userId' => $userId !== null ? (int) $userId : null,
            ],
        ]);

        if ($token === false) {
            return $url;
        }

        return UrlHelper::urlWithToken($url, $token);
    }
}
