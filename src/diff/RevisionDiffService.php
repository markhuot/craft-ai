<?php

namespace markhuot\craftai\diff;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\BaseRelationField;
use craft\fields\Matrix;
use craft\fields\PlainText;

/**
 * Computes a deterministic, field-by-field diff between two versions of an
 * entry (revision↔revision, revision↔current, draft↔canonical — any pair of
 * loaded {@see Entry} instances). Pure PHP with no agent/LLM dependency: the
 * agent narrates *from* this structure, and {@see DiffRenderer} renders it.
 *
 * Both entries should be loaded on the same site; the caller (VersionRef +
 * the tool/controller) is responsible for that.
 *
 * Field handling, by kind:
 *  - text (PlainText, CKEditor, title, slug) → word-level {@see TextDiff}
 *  - relation (Entries/Assets/Categories/Users/Tags) → added/removed/reordered
 *    over the related element ids, with titles resolved for display
 *  - matrix (nested blocks) → block add/remove/reorder + recursive sub-field
 *    diffs, matched by stable canonical block id
 *  - scalar / unknown → opaque from→to comparison of the serialized value
 *
 * The output is intentionally JSON-serializable so it can be returned straight
 * from the `diff_revisions` tool and consumed by the renderer / the front-end.
 */
final class RevisionDiffService
{
    /**
     * @return array{
     *   a: array<string, mixed>,
     *   b: array<string, mixed>,
     *   summary: array{changed: int, added: int, removed: int, unchanged: int},
     *   fields: list<array<string, mixed>>,
     * }
     */
    public function diff(Entry $a, Entry $b): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = $this->systemAttributes($a, $b);
        foreach ($this->diffCustomFields($a, $b) as $row) {
            $fields[] = $row;
        }

        $summary = ['changed' => 0, 'added' => 0, 'removed' => 0, 'unchanged' => 0];
        foreach ($fields as $field) {
            $status = is_string($field['status'] ?? null) ? $field['status'] : 'unchanged';
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return [
            'a' => $this->describe($a),
            'b' => $this->describe($b),
            'summary' => $summary,
            'fields' => $fields,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Entry $entry): array
    {
        $behavior = VersionRef::revisionBehavior($entry);
        $savedBy = null;
        if ($behavior !== null) {
            $savedBy = $behavior->getCreator()?->username;
        }

        return [
            'ref' => VersionRef::refFor($entry),
            'label' => VersionRef::label($entry),
            'revisionNum' => $behavior?->revisionNum,
            'notes' => $behavior?->revisionNotes,
            'savedBy' => $savedBy,
            'dateUpdated' => $entry->dateUpdated?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Entry-level attributes that aren't custom fields but matter in a diff.
     *
     * @return list<array<string, mixed>>
     */
    private function systemAttributes(Entry $a, Entry $b): array
    {
        return [
            $this->textAttr('title', 'Title', (string) ($a->title ?? ''), (string) ($b->title ?? '')),
            $this->textAttr('slug', 'Slug', (string) ($a->slug ?? ''), (string) ($b->slug ?? '')),
            $this->scalarAttr('enabled', 'Enabled', $a->enabled ? 'Enabled' : 'Disabled', $b->enabled ? 'Enabled' : 'Disabled'),
            $this->scalarAttr('postDate', 'Post Date', $this->formatDate($a->postDate), $this->formatDate($b->postDate)),
            $this->scalarAttr('expiryDate', 'Expiry Date', $this->formatDate($a->expiryDate), $this->formatDate($b->expiryDate)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function diffCustomFields(Entry $a, Entry $b): array
    {
        $fieldsA = $this->customFields($a);
        $fieldsB = $this->customFields($b);

        $handles = array_values(array_unique([...array_keys($fieldsA), ...array_keys($fieldsB)]));

        $rows = [];
        foreach ($handles as $handle) {
            $handle = (string) $handle;
            $rows[] = $this->diffField($handle, $fieldsA[$handle] ?? null, $fieldsB[$handle] ?? null, $a, $b);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function diffField(string $handle, ?FieldInterface $fieldA, ?FieldInterface $fieldB, Entry $a, Entry $b): array
    {
        $field = $fieldB ?? $fieldA;
        if ($field === null) {
            return $this->row($handle, $handle, 'unknown', 'unknown', 'unchanged', []);
        }

        $name = is_string($field->name) && $field->name !== '' ? $field->name : $handle;
        $type = $field::displayName();
        $kind = $this->kindOf($field);

        // Present on only one layout → the field itself was added/removed.
        if ($fieldA === null) {
            return $this->row($handle, $name, $type, $kind, 'added', $this->describeValue($field, $b, $handle));
        }
        if ($fieldB === null) {
            return $this->row($handle, $name, $type, $kind, 'removed', $this->describeValue($field, $a, $handle));
        }

        return match ($kind) {
            'relation' => $this->diffRelation($handle, $name, $type, $a, $b),
            'matrix' => $this->diffMatrix($handle, $name, $type, $a, $b),
            'text' => $this->diffText($handle, $name, $type, $field, $a, $b),
            default => $this->diffScalar($handle, $name, $type, $field, $a, $b),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function diffText(string $handle, string $name, string $type, FieldInterface $field, Entry $a, Entry $b): array
    {
        $aVal = $this->stringValue($field, $a, $handle);
        $bVal = $this->stringValue($field, $b, $handle);

        if ($aVal === $bVal) {
            return $this->row($handle, $name, $type, 'text', 'unchanged', []);
        }

        return $this->row($handle, $name, $type, 'text', 'changed', ['textDiff' => TextDiff::segments($aVal, $bVal)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function diffScalar(string $handle, string $name, string $type, FieldInterface $field, Entry $a, Entry $b): array
    {
        $aSer = $field->serializeValue($a->getFieldValue($handle), $a);
        $bSer = $field->serializeValue($b->getFieldValue($handle), $b);

        if ($this->encode($aSer) === $this->encode($bSer)) {
            return $this->row($handle, $name, $type, 'scalar', 'unchanged', []);
        }

        return $this->row($handle, $name, $type, 'scalar', 'changed', [
            'from' => $this->display($aSer),
            'to' => $this->display($bSer),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function diffRelation(string $handle, string $name, string $type, Entry $a, Entry $b): array
    {
        $elemsA = $this->relatedElements($a, $handle);
        $elemsB = $this->relatedElements($b, $handle);

        $idsA = array_map(static fn (ElementInterface $e): int => (int) $e->id, $elemsA);
        $idsB = array_map(static fn (ElementInterface $e): int => (int) $e->id, $elemsB);

        /** @var array<int, string> $titles */
        $titles = [];
        foreach ([...$elemsA, ...$elemsB] as $element) {
            $titles[(int) $element->id] = $this->elementLabel($element);
        }

        $added = array_values(array_diff($idsB, $idsA));
        $removed = array_values(array_diff($idsA, $idsB));
        $reordered = $added === [] && $removed === [] && $idsA !== $idsB;

        $status = ($added === [] && $removed === [] && ! $reordered) ? 'unchanged' : 'changed';

        $expand = static fn (int $id): array => ['id' => $id, 'title' => $titles[$id] ?? ('#'.$id)];

        return $this->row($handle, $name, $type, 'relation', $status, [
            'added' => array_map($expand, $added),
            'removed' => array_map($expand, $removed),
            'reordered' => $reordered,
            'from' => array_map($expand, $idsA),
            'to' => array_map($expand, $idsB),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function diffMatrix(string $handle, string $name, string $type, Entry $a, Entry $b): array
    {
        $blocksA = $this->blocks($a, $handle);
        $blocksB = $this->blocks($b, $handle);

        $keysA = array_keys($blocksA);
        $keysB = array_keys($blocksB);

        $blockRows = [];
        $changed = false;

        foreach ($blocksA as $key => $blockA) {
            if (! isset($blocksB[$key])) {
                $blockRows[] = ['blockId' => (string) $key, 'type' => $this->blockType($blockA), 'status' => 'removed', 'fields' => []];
                $changed = true;
            }
        }

        foreach ($blocksB as $key => $blockB) {
            if (! isset($blocksA[$key])) {
                $blockRows[] = ['blockId' => (string) $key, 'type' => $this->blockType($blockB), 'status' => 'added', 'fields' => []];
                $changed = true;

                continue;
            }

            $subFields = $this->diffCustomFields($blocksA[$key], $blockB);
            $changedSub = array_values(array_filter(
                $subFields,
                static fn (array $row): bool => ($row['status'] ?? 'unchanged') !== 'unchanged',
            ));

            if ($changedSub !== []) {
                $blockRows[] = ['blockId' => (string) $key, 'type' => $this->blockType($blockB), 'status' => 'changed', 'fields' => $changedSub];
                $changed = true;
            }
        }

        // Relative order of the blocks common to both sides.
        $commonA = array_values(array_filter($keysA, static fn ($k): bool => isset($blocksB[$k])));
        $commonB = array_values(array_filter($keysB, static fn ($k): bool => isset($blocksA[$k])));
        $reordered = $commonA !== $commonB;
        if ($reordered) {
            $changed = true;
        }

        return $this->row($handle, $name, $type, 'matrix', $changed ? 'changed' : 'unchanged', [
            'blocks' => $blockRows,
            'reordered' => $reordered,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeValue(FieldInterface $field, Entry $entry, string $handle): array
    {
        return ['value' => $this->display($field->serializeValue($entry->getFieldValue($handle), $entry))];
    }

    /**
     * @return array<string, FieldInterface>
     */
    private function customFields(Entry $entry): array
    {
        $layout = $entry->getFieldLayout();
        if ($layout === null) {
            return [];
        }

        $out = [];
        foreach ($layout->getCustomFields() as $field) {
            $handle = $field->handle;
            if (is_string($handle) && $handle !== '') {
                $out[$handle] = $field;
            }
        }

        return $out;
    }

    /**
     * Nested Matrix blocks keyed by a stable id (the canonical block id, which
     * survives across revisions of the owner), order preserved.
     *
     * @return array<int|string, Entry>
     */
    private function blocks(Entry $entry, string $handle): array
    {
        $value = $entry->getFieldValue($handle);
        if (! $value instanceof ElementQueryInterface) {
            return [];
        }

        $out = [];
        foreach ($value->all() as $block) {
            if (! $block instanceof Entry) {
                continue;
            }
            $key = $block->getCanonicalId() ?? $block->id;
            if ($key !== null) {
                $out[$key] = $block;
            }
        }

        return $out;
    }

    private function blockType(Entry $block): string
    {
        try {
            return $block->getType()->handle ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<ElementInterface>
     */
    private function relatedElements(Entry $entry, string $handle): array
    {
        $value = $entry->getFieldValue($handle);
        if (! $value instanceof ElementQueryInterface) {
            return [];
        }

        $out = [];
        foreach ($value->all() as $element) {
            if ($element instanceof ElementInterface) {
                $out[] = $element;
            }
        }

        return $out;
    }

    private function kindOf(?FieldInterface $field): string
    {
        if ($field === null) {
            return 'unknown';
        }
        if ($field instanceof BaseRelationField) {
            return 'relation';
        }
        if ($field instanceof Matrix) {
            return 'matrix';
        }
        if ($field instanceof PlainText) {
            return 'text';
        }
        if (is_a($field, 'craft\\ckeditor\\Field')) {
            return 'text';
        }

        return 'scalar';
    }

    private function stringValue(FieldInterface $field, Entry $entry, string $handle): string
    {
        return $this->encode($field->serializeValue($entry->getFieldValue($handle), $entry));
    }

    private function elementLabel(ElementInterface $element): string
    {
        $label = trim($element->getUiLabel());

        return $label !== '' ? $label : ('#'.($element->id ?? '?'));
    }

    private function formatDate(?\DateTimeInterface $date): string
    {
        return $date === null ? '' : $date->format('Y-m-d H:i');
    }

    /**
     * Compact, comparison-friendly encoding (no whitespace) used for equality.
     */
    private function encode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    /**
     * Human-readable rendering of a value for from→to display (pretty JSON for
     * arrays/objects).
     */
    private function display(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '' : $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function textAttr(string $handle, string $name, string $aVal, string $bVal): array
    {
        if ($aVal === $bVal) {
            return $this->row($handle, $name, 'System', 'text', 'unchanged', []);
        }

        return $this->row($handle, $name, 'System', 'text', 'changed', ['textDiff' => TextDiff::segments($aVal, $bVal)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scalarAttr(string $handle, string $name, string $aVal, string $bVal): array
    {
        if ($aVal === $bVal) {
            return $this->row($handle, $name, 'System', 'scalar', 'unchanged', []);
        }

        return $this->row($handle, $name, 'System', 'scalar', 'changed', ['from' => $aVal, 'to' => $bVal]);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function row(string $handle, string $name, string $type, string $kind, string $status, array $detail): array
    {
        return [
            'handle' => $handle,
            'name' => $name,
            'type' => $type,
            'kind' => $kind,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
