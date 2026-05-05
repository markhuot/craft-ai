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
     * @param array<string, mixed> $element
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
}
