<?php

namespace markhuot\craftai\helpers;

use markhuot\craftai\agent\ClientType;
use markhuot\craftai\agent\ToolContext;

/**
 * Wraps a saved-element payload with a `notes` field for the calling surface.
 *
 * On the CP chat surface (where {@see ToolContext::getClient()} reports
 * {@see ClientType::CP}), the note prompts the agent to call `open_preview`
 * with the saved element's URL — that's what loads the front-end into the CP
 * preview pane so the user can see the change. On every other surface — MCP,
 * the front-end widget, console/queue/tests — `open_preview` either isn't
 * registered (MCP) or has no preview pane to act on (widget), so we suppress
 * the suggestion and emit a generic "<Noun> saved." note instead. That keeps
 * a stable wrapper shape across surfaces and gives us a dedicated channel for
 * any future surface-specific guidance.
 *
 * On the CP surface with no URL (e.g. a section without a URI format, or an
 * asset on a filesystem that doesn't expose URLs) we skip the wrap entirely —
 * there's no preview to point at, and emitting an empty suggestion would just
 * be noise. Off-CP surfaces still wrap regardless of URL availability so the
 * shape stays consistent.
 */
class PreviewSuggestion
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function wrap(array $data, ?string $url, string $key, ToolContext $context): array
    {
        $noun = ucfirst($key);

        if ($context->getClient() !== ClientType::CP) {
            return [
                'notes' => "{$noun} saved.",
                $key => $data,
            ];
        }

        if ($url === null || $url === '') {
            return $data;
        }

        return [
            'notes' => "{$noun} saved. Show the user the result by calling open_preview with this url: {$url}",
            $key => $data,
        ];
    }
}
