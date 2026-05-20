<?php

namespace markhuot\craftai\helpers;

use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;

/**
 * Wraps a saved-element payload with a `notes` field for the calling surface.
 *
 * Two pieces of surface-specific guidance can ride along on the note:
 *
 *  - `open_preview` prompt — only on {@see ClientType::CP}, the full-page
 *    CP chat surface that owns the preview pane. The note hands the
 *    agent the front-end URL it should load so the user can see the
 *    change rendered in place. Other surfaces have no preview pane to
 *    drive, so the suggestion is suppressed.
 *
 *  - cpEditUrl prompt — on CP *and* the front-end widget, both
 *    browser-based surfaces. The note tells the agent to include a link
 *    back to the CP edit screen in its reply so the user can pop over
 *    to review (and tweak) the saved element. Off the browser
 *    (MCP / console / queue / tests) the link is useless, so we leave
 *    it out.
 *
 * When neither piece of guidance applies on CP (no URL and no
 * cpEditUrl) we return the payload unwrapped — emitting an empty
 * "<Noun> saved." would be noise. Off-CP surfaces always wrap so the
 * shape stays consistent for downstream consumers; the note degrades
 * to the bare "<Noun> saved." when there's nothing surface-specific
 * to add.
 */
class PreviewSuggestion
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  ?string                  $url        Front-end URL the agent should call open_preview with (CP only).
     * @param  ?string                  $cpEditUrl  Craft CP edit URL for the saved element — surfaced on CP + Widget so the agent can link the user back to review.
     * @return array<array-key, mixed>
     */
    public static function wrap(
        array $data,
        ?string $url,
        string $key,
        ToolContext $context,
        ?string $cpEditUrl = null,
    ): array {
        $noun = ucfirst($key);
        $client = $context->getClient();

        // Surfaces that don't render in a browser (MCP, console, queue,
        // tests with no client set) only get the generic "<Noun> saved."
        // — a cpEditUrl wouldn't open anywhere useful for them.
        if ($client !== ClientType::CP && $client !== ClientType::WIDGET) {
            return [
                'notes' => "{$noun} saved.",
                $key => $data,
            ];
        }

        $isCp = $client === ClientType::CP;
        $hasPreview = $isCp && $url !== null && $url !== '';
        $hasEditLink = $cpEditUrl !== null && $cpEditUrl !== '';

        if (! $hasPreview && ! $hasEditLink) {
            // Preserve the historical "skip wrap entirely" behavior on
            // CP — there's no preview pane to drive and no edit link to
            // share, so an empty suggestion would just be noise. The
            // widget surface still wraps with the generic note so its
            // own consumers see a stable shape.
            if ($isCp) {
                return $data;
            }
            return [
                'notes' => "{$noun} saved.",
                $key => $data,
            ];
        }

        $parts = ["{$noun} saved."];
        if ($hasPreview) {
            $parts[] = "Show the user the result by calling open_preview with this url: {$url}";
        }
        if ($hasEditLink) {
            // Phrased as a chat-response instruction (not a "call this
            // tool" instruction) — there's no tool that "opens" the CP
            // edit screen; the agent just needs to include the URL in
            // its reply so the user can click through.
            $parts[] = "When you reply to the user, include this link so they can review and edit the change in the Craft control panel: {$cpEditUrl}";
        }

        return [
            'notes' => implode(' ', $parts),
            $key => $data,
        ];
    }
}
