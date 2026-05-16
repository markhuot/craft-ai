<?php

namespace markhuot\craftai\agent;

/**
 * Translates the page-context payload that the front-end widget sends with a
 * user message into a human-readable system note we can persist as a message
 * row and replay to the LLM. We deliberately don't keep the raw structured
 * payload — once it's been folded into prose, the model only ever sees the
 * note, and the note is what shows up in the chat transcript.
 */
class PageContextSerializer
{
    /**
     * Stable hash of a context payload. Uses canonical JSON (sorted keys,
     * no escaped slashes) so two semantically equal payloads always produce
     * the same fingerprint regardless of insertion order.
     *
     * @param array<string, mixed> $context
     */
    public static function fingerprint(array $context): string
    {
        $canonical = self::canonicalize($context);
        return sha1(json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Render a context payload as a self-contained system note. The output is
     * stable for a given input so tests can assert on substrings.
     *
     * @param array<string, mixed> $context
     */
    public static function toSystemNote(array $context): string
    {
        // CodeComponent field authoring has its own framing — the user is in
        // the CP editing a specific field, not browsing the public site —
        // so route those payloads through a dedicated branch that explains
        // the agent's role and embeds the live tab values. Falls through to
        // the standard page-context prelude when no field-author block is
        // present.
        $fieldAuthor = is_array($context['fieldAuthor'] ?? null) ? $context['fieldAuthor'] : null;
        if ($fieldAuthor !== null && self::isCodeComponentFieldAuthor($fieldAuthor)) {
            return self::toCodeComponentFieldNote($fieldAuthor, $context);
        }

        $url = self::stringOrNull($context['url'] ?? null);
        $path = self::stringOrNull($context['path'] ?? null);
        $template = self::stringOrNull($context['template'] ?? null);
        $siteHandle = self::stringOrNull($context['siteHandle'] ?? null);
        $query = is_array($context['query'] ?? null) ? $context['query'] : [];
        $element = is_array($context['element'] ?? null) ? $context['element'] : null;

        $lines = [];
        if ($url !== null) {
            $lines[] = "URL: {$url}";
        } elseif ($path !== null) {
            $lines[] = "Path: {$path}";
        }
        if ($siteHandle !== null) {
            $lines[] = "Site: {$siteHandle}";
        }
        if ($template !== null) {
            $lines[] = "Template: {$template}";
        }
        if ($query !== []) {
            $rendered = self::renderQuery($query);
            if ($rendered !== '') {
                $lines[] = "Query: {$rendered}";
            }
        }
        if ($element !== null) {
            $rendered = self::renderElement($element);
            if ($rendered !== null) {
                $lines[] = "Element: {$rendered}";
            }
        } else {
            $lines[] = 'Element: (no element matched this URL)';
        }

        $body = implode("\n", $lines);

        return "<page-context>\nThe user is viewing this page on the front-end of the site. "
            ."Use it as background when relevant; call tools if you need more detail.\n\n"
            .$body
            ."\n</page-context>";
    }

    /**
     * @param array<array-key, mixed> $element
     */
    private static function renderElement(array $element): ?string
    {
        $type = self::stringOrNull($element['type'] ?? null);
        $id = $element['id'] ?? null;
        $title = self::stringOrNull($element['title'] ?? null);
        $sectionHandle = self::stringOrNull($element['sectionHandle'] ?? null);

        if ($type === null && $id === null) {
            return null;
        }

        $parts = [];
        $parts[] = $type ?? 'element';
        if (is_int($id) || (is_string($id) && $id !== '')) {
            $parts[] = "#{$id}";
        }
        if ($title !== null && $title !== '') {
            $parts[] = '"'.$title.'"';
        }
        $head = implode(' ', $parts);

        $tail = [];
        if ($sectionHandle !== null && $sectionHandle !== '') {
            $tail[] = "section: {$sectionHandle}";
        }

        return $tail === [] ? $head : $head.' ('.implode(', ', $tail).')';
    }

    /**
     * @param array<string|int, mixed> $query
     */
    private static function renderQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $key => $value) {
            $rendered = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);
            if (! is_string($rendered)) {
                continue;
            }
            $parts[] = $key.'='.$rendered;
        }

        return implode('&', $parts);
    }

    /**
     * Recursively sort array keys so the JSON encoding is deterministic.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        return null;
    }

    /**
     * @param  array<array-key, mixed>  $fieldAuthor
     */
    private static function isCodeComponentFieldAuthor(array $fieldAuthor): bool
    {
        $kind = $fieldAuthor['kind'] ?? null;

        return is_string($kind) && $kind === 'code-component-field';
    }

    /**
     * Render the framing the agent sees when the user is editing a Code
     * Component field. The block lives at the top of the system note so
     * the agent reads the role + tool callout before any per-tab content.
     *
     * @param  array<array-key, mixed>  $fieldAuthor
     * @param  array<string, mixed>  $context
     */
    private static function toCodeComponentFieldNote(array $fieldAuthor, array $context): string
    {
        $handle = self::stringOrNull($fieldAuthor['fieldHandle'] ?? null);
        $name = self::stringOrNull($fieldAuthor['fieldName'] ?? null);
        $fieldId = $fieldAuthor['fieldId'] ?? null;
        $values = is_array($fieldAuthor['currentValues'] ?? null) ? $fieldAuthor['currentValues'] : [];

        $element = is_array($context['element'] ?? null) ? $context['element'] : null;

        $headerParts = [];
        if ($name !== null) {
            $headerParts[] = '"'.$name.'"';
        }
        $meta = [];
        if ($handle !== null) {
            $meta[] = "handle: {$handle}";
        }
        if (is_int($fieldId) && $fieldId > 0) {
            $meta[] = "id: {$fieldId}";
        } elseif (is_string($fieldId) && $fieldId !== '') {
            $meta[] = "id: {$fieldId}";
        }
        if ($meta !== []) {
            $headerParts[] = '('.implode(', ', $meta).')';
        }
        $fieldHeader = $headerParts === []
            ? 'Field: (unknown)'
            : 'Field: '.implode(' ', $headerParts);

        $elementLine = null;
        if ($element !== null) {
            $rendered = self::renderElement($element);
            if ($rendered !== null) {
                $elementLine = "Element: {$rendered}";
            }
        }
        if ($elementLine === null) {
            $elementLine = 'Element: (entry not yet saved)';
        }

        $body = [];
        $body[] = $fieldHeader;
        $body[] = $elementLine;
        $body[] = '';
        $body[] = 'Current Twig:';
        $body[] = self::tabFence('twig', $values['twig'] ?? null);
        $body[] = '';
        $body[] = 'Current CSS:';
        $body[] = self::tabFence('css', $values['css'] ?? null);
        $body[] = '';
        $body[] = 'Current JS:';
        $body[] = self::tabFence('js', $values['js'] ?? null);

        $preamble = 'The user is editing a Code Component custom field in the Craft control panel. '
            ."Anything you author with them needs to be written back into the field via the "
            ."`update_code_component` tool — pass the field handle plus any of `twig`, `css`, "
            ."or `js` and the changes appear live in the user's editor. Drafts are the rollback "
            ."mechanism; prefer writing to a draft when iterating.";

        return "<code-component-context>\n"
            .$preamble."\n\n"
            .implode("\n", $body)
            ."\n</code-component-context>";
    }

    /**
     * Render one tab's current value as a fenced code block, or "(empty)"
     * when the tab is blank. Trailing newlines are trimmed so the fence
     * always closes flush against the value.
     */
    private static function tabFence(string $lang, mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '(empty)';
        }

        return "```{$lang}\n".rtrim($value, "\n")."\n```";
    }
}
