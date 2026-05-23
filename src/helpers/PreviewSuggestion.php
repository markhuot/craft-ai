<?php

namespace markhuot\craftai\helpers;

use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;

/**
 * Builds the full `{_notes, data}` envelope a saved-element tool returns,
 * folding any surface-specific guidance into the same `_notes` string the
 * tool's own narration lives on. The shape is stable across every surface
 * — `data.{noun}` is always where the element payload lives — and there's
 * only one place an LLM-facing note can appear, so the agent never has to
 * juggle a top-level note plus a nested one.
 *
 * Surface-specific call-to-actions appended to `_notes`:
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
 * When neither guidance piece applies, `_notes` is just the tool's own
 * note unchanged.
 */
class PreviewSuggestion
{
    /**
     * @param  string                   $notes      Tool-authored "what just happened" narration (e.g. "Created entry id=42. Use get_entry to fetch…").
     * @param  array<array-key, mixed>  $data       Serialized form of the saved element.
     * @param  string                   $key        Noun the element lives under inside `data` (e.g. 'entry', 'asset', 'draft').
     * @param  ?string                  $url        Front-end URL the agent should call open_preview with (CP only).
     * @param  ToolContext              $context    Active tool context — determines which surface is asking.
     * @param  ?string                  $cpEditUrl  Craft CP edit URL for the saved element — surfaced on CP + Widget so the agent can link the user back to review.
     * @return array{_notes: string, data: array<string, array<array-key, mixed>>}
     */
    public static function wrap(
        string $notes,
        array $data,
        string $key,
        ?string $url,
        ToolContext $context,
        ?string $cpEditUrl = null,
    ): array {
        $client = $context->getClient();
        $isCp = $client === ClientType::CP;
        $isBrowserSurface = $isCp || $client === ClientType::WIDGET;

        // Preview is a CP-only concept — only the full-page chat hosts
        // the iframe. Widget and other surfaces never get the prompt.
        $hasPreview = $isCp && $url !== null && $url !== '';
        // CP edit links only help on browser surfaces. MCP / console /
        // queue clients can't click through, so we drop the link there.
        $hasEditLink = $isBrowserSurface && $cpEditUrl !== null && $cpEditUrl !== '';

        $parts = [$notes];
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
            '_notes' => implode(' ', $parts),
            'data' => [$key => $data],
        ];
    }
}
