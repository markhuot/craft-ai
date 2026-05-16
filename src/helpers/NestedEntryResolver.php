<?php

namespace markhuot\craftai\helpers;

use Craft;
use craft\elements\Entry;
use RuntimeException;

/**
 * Resolves structured CKEditor field values that inline-create nested entries
 * owned by a parent entry. Mirrors Matrix's `{newN: {...}}` placeholder
 * convention so the agent can express a whole story-plus-components payload
 * in one upsert_entry call.
 *
 * Structured value shape (per CKEditor field handle):
 *
 *     {
 *       "html": "<p>...</p><craft-entry data-entry-id=\"new1\"></craft-entry>",
 *       "entries": {
 *         "new1": {"type": "<entryTypeHandle>", "title": "...", "fields": {...}}
 *       }
 *     }
 *
 * Each placeholder key (e.g. `new1`) must appear in the HTML as
 * `data-entry-id="<placeholder>"`. Numeric `data-entry-id` values are left
 * alone — those are existing entries referenced by ID.
 */
final class NestedEntryResolver
{
    /**
     * @param array<string, mixed> $structuredFields  field handle => structured value
     * @return array<string, string>                  field handle => resolved HTML
     */
    public static function resolve(array $structuredFields, Entry $owner): array
    {
        if ($owner->id === null) {
            throw new RuntimeException('Owner entry must be saved before nested entries can be created.');
        }

        $resolved = [];
        foreach ($structuredFields as $handle => $value) {
            if (! self::isStructured($value)) {
                throw new RuntimeException("Field \"$handle\" was passed to NestedEntryResolver without a structured {html, entries} payload.");
            }
            $resolved[$handle] = self::resolveOne($handle, $value, $owner);
        }

        return $resolved;
    }

    /**
     * @phpstan-assert-if-true array{html: string, entries: array<string, mixed>} $value
     */
    public static function isStructured(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists('html', $value)
            && array_key_exists('entries', $value)
            && is_string($value['html'])
            && is_array($value['entries']);
    }

    /**
     * @param array{html: string, entries: array<string, mixed>} $value
     */
    private static function resolveOne(string $handle, array $value, Entry $owner): string
    {
        $layout = $owner->getFieldLayout();
        $field = $layout?->getFieldByHandle($handle);
        if ($field === null) {
            throw new RuntimeException("Field \"$handle\" is not on this entry's field layout.");
        }
        if (! $field instanceof \craft\ckeditor\Field) {
            throw new RuntimeException("Field \"$handle\" is not a CKEditor field — structured {html, entries} payloads are only supported for CKEditor fields.");
        }
        if ($field->id === null) {
            throw new RuntimeException("Field \"$handle\" has no id — refusing to create nested entries against an unsaved field.");
        }
        $fieldId = (int) $field->id;

        $html = $value['html'];
        $entries = $value['entries'];

        $allowedTypes = [];
        foreach ($field->getEntryTypes() as $et) {
            $allowedTypes[$et->handle] = $et;
        }

        $placeholdersInHtml = self::findPlaceholders($html);
        $unusedPlaceholders = array_diff(array_keys($entries), $placeholdersInHtml);
        $missingDefinitions = array_diff($placeholdersInHtml, array_keys($entries));

        if ($unusedPlaceholders !== []) {
            throw new RuntimeException(sprintf(
                'Nested entry placeholder(s) [%s] defined in `entries` but not referenced in the field HTML.',
                implode(', ', $unusedPlaceholders),
            ));
        }
        if ($missingDefinitions !== []) {
            throw new RuntimeException(sprintf(
                'Nested entry placeholder(s) [%s] referenced in the field HTML but not defined in `entries`.',
                implode(', ', $missingDefinitions),
            ));
        }

        $idMap = [];
        foreach ($entries as $placeholder => $spec) {
            if (! is_array($spec)) {
                throw new RuntimeException("Nested entry \"$placeholder\" must be an object.");
            }

            $typeHandle = $spec['type'] ?? null;
            if (! is_string($typeHandle) || $typeHandle === '') {
                throw new RuntimeException("Nested entry \"$placeholder\" is missing a `type` (entry type handle).");
            }
            if (! isset($allowedTypes[$typeHandle])) {
                throw new RuntimeException(sprintf(
                    'Entry type "%s" is not in the CKEditor field "%s" allowlist. Allowed: [%s].',
                    $typeHandle,
                    $handle,
                    implode(', ', array_keys($allowedTypes)),
                ));
            }

            $typeId = $allowedTypes[$typeHandle]->id;
            if ($typeId === null) {
                throw new RuntimeException("Entry type \"$typeHandle\" has no id — cannot create nested entry \"$placeholder\".");
            }

            $nested = new Entry();
            $nested->fieldId = $fieldId;
            $nested->setOwner($owner);
            $nested->typeId = $typeId;
            if (isset($spec['title']) && is_string($spec['title'])) {
                $nested->title = $spec['title'];
            }
            if (isset($spec['slug']) && is_string($spec['slug'])) {
                $nested->slug = $spec['slug'];
            }
            if (isset($spec['enabled']) && is_bool($spec['enabled'])) {
                $nested->enabled = $spec['enabled'];
            } else {
                $nested->enabled = true;
            }
            if (isset($spec['fields']) && is_array($spec['fields'])) {
                /** @var array<string, mixed> $nestedFields */
                $nestedFields = $spec['fields'];
                $nested->setFieldValues($nestedFields);
            }

            if (! Craft::$app->elements->saveElement($nested)) {
                $errors = $nested->getErrorSummary(true);
                throw new RuntimeException(sprintf(
                    'Could not save nested entry "%s": %s',
                    $placeholder,
                    implode('; ', $errors),
                ));
            }

            $idMap[$placeholder] = (int) $nested->id;
        }

        return self::substitutePlaceholders($html, $idMap);
    }

    /**
     * @return list<string>  unique non-numeric placeholder values found in the HTML
     */
    private static function findPlaceholders(string $html): array
    {
        $matches = [];
        if (preg_match_all('/data-entry-id=["\']([^"\']+)["\']/i', $html, $matches) === false) {
            return [];
        }

        $placeholders = [];
        foreach ($matches[1] as $value) {
            if (! ctype_digit($value)) {
                $placeholders[$value] = true;
            }
        }

        return array_keys($placeholders);
    }

    /**
     * @param array<string, int> $idMap
     */
    private static function substitutePlaceholders(string $html, array $idMap): string
    {
        foreach ($idMap as $placeholder => $realId) {
            $html = str_replace(
                [
                    'data-entry-id="'.$placeholder.'"',
                    "data-entry-id='".$placeholder."'",
                ],
                'data-entry-id="'.$realId.'"',
                $html,
            );
        }

        return $html;
    }
}
