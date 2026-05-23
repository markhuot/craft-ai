<?php

namespace markhuot\craftai\helpers;

use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;

/**
 * Wraps a saved-element payload under a `{noun}` key alongside a `notes`
 * field for the calling surface. The shape is identical on every surface
 * so downstream consumers (tests, agent prompt rendering, MCP clients)
 * can always reach the element data at `data.{noun}` regardless of where
 * the tool was invoked from.
 *
 * `notes` carries surface-specific guidance for the LLM:
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
 * When neither guidance piece applies the note degrades to the bare
 * "<Noun> saved." string — the wrap shape stays stable either way.
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
        $isCp = $client === ClientType::CP;
        $isBrowserSurface = $isCp || $client === ClientType::WIDGET;

        // Preview is a CP-only concept — only the full-page chat hosts
        // the iframe. Widget and other surfaces never get the prompt.
        $hasPreview = $isCp && $url !== null && $url !== '';
        // CP edit links only help on browser surfaces. MCP / console /
        // queue clients can't click through, so we drop the link there.
        $hasEditLink = $isBrowserSurface && $cpEditUrl !== null && $cpEditUrl !== '';

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
