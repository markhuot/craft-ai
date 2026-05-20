<?php

namespace markhuot\craftai\helpers;

use Craft;
use craft\elements\Entry;
use markhuot\craftai\records\CommentRecord;

/**
 * Server-side cleanup for the `<span data-craft-ai-comment-id="…">`
 * markers the CKEditor plugin writes when an editor leaves a span-
 * scoped comment.
 *
 * When a comment is resolved (via the CP popover, the dedicated chat
 * page, or the agent's `resolve_comment` tool) we want the highlight
 * to disappear from the field's saved HTML — not just from whichever
 * editor instance happens to be open. Doing the rewrite server-side
 * keeps every entry-point consistent and lets Craft's built-in
 * "this entry was updated, reload?" toast handle the front-end
 * refresh without any per-surface JS wiring.
 *
 * Field-level (no `referenceId`) and whole-entry (no `fieldHandle`)
 * comments don't write markers into the HTML, so they short-circuit
 * out — the helper is a no-op for those.
 */
final class CommentMarkerCleanup
{
    /**
     * Strip the `<span data-craft-ai-comment-id="…">` wrapper for a
     * single resolved comment from its host field's HTML, save the
     * element, and let downstream resave / search-index hooks fire
     * normally.
     *
     * Failures are swallowed and logged: a resolve action that
     * succeeds in marking the row as resolved shouldn't also fail
     * because we couldn't rewrite the field's HTML. The cleanup is
     * cosmetic — the comment's status is the source of truth.
     */
    public static function unwrapResolved(CommentRecord $record): void
    {
        $referenceId = $record->referenceId;
        $fieldHandle = $record->fieldHandle;
        if ($referenceId === null || $referenceId === '' || $fieldHandle === null || $fieldHandle === '') {
            return;
        }

        try {
            $entry = $record->isDraft
                ? Entry::find()->draftId((int) $record->elementId)->status(null)->one()
                : Entry::find()->id((int) $record->elementId)->status(null)->one();

            if (! $entry instanceof Entry) {
                return;
            }

            $rawHtml = self::extractHtml($entry->getFieldValue($fieldHandle));
            if ($rawHtml === null || $rawHtml === '') {
                return;
            }

            $newHtml = self::stripMarker($rawHtml, $referenceId);
            if ($newHtml === $rawHtml) {
                return;
            }

            $entry->setFieldValue($fieldHandle, $newHtml);
            Craft::$app->getElements()->saveElement($entry);
        } catch (\Throwable $e) {
            Craft::warning(
                sprintf(
                    'craft-ai: failed to strip span marker %s from comment #%d: %s',
                    $referenceId,
                    (int) $record->id,
                    $e->getMessage(),
                ),
                __METHOD__,
            );
        }
    }

    /**
     * CKEditor field values arrive as `craft\ckeditor\data\FieldData`
     * objects, which stringify to the underlying HTML. Other shapes
     * (plain string from a raw set, or a closure-bound value object
     * from a custom field type) are handled by best-effort coercion.
     */
    private static function extractHtml(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }
        return null;
    }

    /**
     * Locate and unwrap every `<span>` carrying the given marker id.
     * Uses DOMDocument rather than a regex so we correctly handle:
     *   - spans wrapping arbitrary inline content (links, images, nested formatting),
     *   - attribute order variation across the field's lifecycle,
     *   - escaped or HTML-entity-laden text inside the span.
     *
     * If the rewrite would change nothing (no matching span on the
     * page — content edited around the marker, or already stripped),
     * we return the original string verbatim so callers can short-
     * circuit without a save.
     *
     * Exposed `public` so unit tests can exercise the HTML rewrite in
     * isolation, without the full Entry/CKEditor stack the calling
     * `unwrapResolved` requires.
     */
    public static function stripMarker(string $html, string $referenceId): string
    {
        // Cheap pre-check — the id is a UUID so substring containment
        // is a reliable "is the marker plausibly in this HTML" guard
        // that lets us skip the DOMDocument round-trip for fields
        // without the marker (the common case across multi-field saves).
        if (! str_contains($html, $referenceId)) {
            return $html;
        }

        $doc = new \DOMDocument();
        // libxml's HTML parser is too strict for CKEditor output;
        // suppress warnings and wrap in a synthetic root so we don't
        // pick up implicit `<html><body>` injection.
        $wrapped = '<?xml encoding="UTF-8"?><div id="craftai-cleanup-root">'.$html.'</div>';
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($doc);
        $query = sprintf(
            '//span[@data-craft-ai-comment-id=%s]',
            self::xpathLiteral($referenceId),
        );
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return $html;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            self::unwrapElement($node);
        }

        $root = $doc->getElementById('craftai-cleanup-root');
        if (! $root instanceof \DOMElement) {
            return $html;
        }

        $rebuilt = '';
        foreach ($root->childNodes as $child) {
            $rebuilt .= $doc->saveHTML($child);
        }
        return $rebuilt;
    }

    /**
     * Move every child of `$node` to be its sibling before the node
     * itself, then remove the node. The net effect is "drop the
     * wrapper, keep the contents" — i.e. the standard DOM unwrap.
     */
    private static function unwrapElement(\DOMElement $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }
        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    /**
     * XPath has no escape syntax for string literals, so a value
     * containing both quote characters has to be split and recombined
     * via `concat()`. The marker id is a UUID in practice (no quotes
     * at all) but we handle the general case so a future referenceId
     * scheme can't introduce an injection vector via the xpath query.
     */
    private static function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'$value'";
        }
        if (! str_contains($value, '"')) {
            return "\"$value\"";
        }
        $parts = explode("'", $value);
        $pieces = [];
        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $pieces[] = "'$part'";
            }
            if ($i < count($parts) - 1) {
                $pieces[] = "\"'\"";
            }
        }
        return 'concat('.implode(',', $pieces).')';
    }
}
