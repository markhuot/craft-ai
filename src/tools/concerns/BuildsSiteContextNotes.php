<?php

namespace markhuot\craftai\tools\concerns;

use Craft;
use craft\base\Field;
use craft\elements\Entry;
use craft\models\Site;

/**
 * Shared site/translation context formatting for read tools that return a
 * single Entry — canonical or draft. Both get_entry and get_draft need to
 * (a) tell the agent which site the row came back from, and (b) flag any
 * custom field whose translationMethod is not "site" so that a follow-up
 * upsert_entry/upsert_draft with `site=<other>` doesn't silently overwrite
 * the source.
 */
trait BuildsSiteContextNotes
{
    /**
     * Render the " on site \"X\" (id=N, language=\"Y\")" suffix used in
     * tool _notes strings. Returns empty when no site is known so the
     * caller can interpolate unconditionally.
     */
    private function renderSiteContext(?Site $site): string
    {
        if ($site === null) {
            return '';
        }

        return sprintf(
            ' on site "%s" (id=%d, language="%s")',
            $site->handle ?? '',
            $site->id ?? 0,
            $site->language ?? '',
        );
    }

    /**
     * Returns the "⚠️ Translation caution — ..." sentence to append to a
     * note, or empty string when every custom field on the entry's type
     * is per-site (i.e. nothing to warn about).
     */
    private function renderTranslationCaution(Entry $entry): string
    {
        $nonSiteFields = $this->nonSiteTranslationFields($entry);
        if ($nonSiteFields === []) {
            return '';
        }

        return ' ⚠️ Translation caution — these custom fields are NOT per-site, so saving them via upsert_entry/upsert_draft with `site=<other>` will overwrite the value on every site: '
            .implode('; ', $nonSiteFields)
            .'. To make a field translatable, call upsert_field with `translationMethod="site"`.';
    }

    /**
     * Lookup the Craft Site an Entry instance belongs to. Returns null
     * when the entry has no siteId or the site has been deleted.
     */
    private function siteOfEntry(Entry $entry): ?Site
    {
        if ($entry->siteId === null) {
            return null;
        }

        return Craft::$app->sites->getSiteById($entry->siteId);
    }

    /**
     * One descriptive string per custom field whose translationMethod
     * is NOT "site" — i.e. whose value is shared across more than one
     * site. Empty list when everything is per-site.
     *
     * @return list<string>
     */
    private function nonSiteTranslationFields(Entry $entry): array
    {
        $type = $entry->getType();
        $layout = $type->getFieldLayout();

        $out = [];
        foreach ($layout->getCustomFields() as $field) {
            if (! $field instanceof Field) {
                continue;
            }
            $method = $field->translationMethod;
            if ($method === Field::TRANSLATION_METHOD_SITE) {
                continue;
            }
            $handle = $field->handle ?? '';
            if ($handle === '') {
                continue;
            }
            $out[] = "{$handle} (translationMethod=\"{$method}\")";
        }

        return $out;
    }
}
