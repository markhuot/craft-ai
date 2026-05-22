<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\base\Field;
use craft\elements\Entry;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\validators\ExistingEntryType;
use markhuot\craftai\validators\ExistingSection;
use markhuot\craftai\validators\ExistingSite;

/**
 * Search for content entries in the CMS. Returns a list of entries matching
 * the given filters. All parameters are optional and can be combined.
 *
 * Each result includes the entry's ID, title, status, section, URL, and custom
 * field values. Results default to 25 — use limit and offset to paginate.
 *
 * On multi-site installs, pass `site` to read entries as they exist on that
 * locale. The response `_notes` always names the site the rows came back
 * from and flags any custom fields whose translationMethod is not "site" —
 * those share their value across locales, so saving them with a non-primary
 * site will overwrite the source.
 */
class GetEntries extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<array-key, mixed>>}
     */
    public function __invoke(
        #[Description('Full-text search query (e.g. "pricing page")')]
        ?string $search = null,
        #[Description('Section handle to filter by (e.g. "news", "blog")')]
        #[Validate(ExistingSection::class)]
        ?string $section = null,
        #[Description('Entry type handle to filter by (e.g. "article", "page")')]
        #[Validate(ExistingEntryType::class)]
        ?string $type = null,
        #[Description('Status filter: "live" (default), "pending", "expired", "disabled", or "any" for all')]
        #[Validate('in', range: ['live', 'pending', 'expired', 'disabled', 'any'])]
        ?string $status = null,
        #[Description('Author user ID to filter by')]
        ?int $authorId = null,
        #[Description('Filter by exact title')]
        ?string $title = null,
        #[Description('Filter by URL slug')]
        ?string $slug = null,
        #[Description('Filter by URI path')]
        ?string $uri = null,
        #[Description('Site handle for multi-site installs (e.g. "english", "french")')]
        #[Validate(ExistingSite::class)]
        ?string $site = null,
        #[Description('Return only entries posted before this date (e.g. "2024-01-01", "today", "3 months ago")')]
        ?string $before = null,
        #[Description('Return only entries posted on or after this date (e.g. "2024-01-01", "yesterday")')]
        ?string $after = null,
        #[Description('Structure level to filter by (1 = top-level)')]
        ?int $level = null,
        #[Description('Sort order (e.g. "title ASC", "postDate DESC", "dateUpdated ASC")')]
        ?string $orderBy = null,
        #[Description('Maximum number of entries to return (default 25)')]
        ?int $limit = 25,
        #[Description('Number of entries to skip for pagination')]
        ?int $offset = null,
    ): array {
        $query = Entry::find();

        if ($search !== null) {
            $query->search($search);
        }

        if ($section !== null) {
            $query->section($section);
        }

        if ($type !== null) {
            $query->type($type);
        }

        if ($status !== null) {
            if ($status === 'any') {
                $query->status(null);
            } else {
                $query->status($status);
            }
        }

        if ($authorId !== null) {
            $query->authorId($authorId);
        }

        if ($title !== null) {
            $query->title($title);
        }

        if ($slug !== null) {
            $query->slug($slug);
        }

        if ($uri !== null) {
            $query->uri($uri);
        }

        if ($site !== null) {
            $query->site($site);
        }

        if ($before !== null) {
            $query->before($before);
        }

        if ($after !== null) {
            $query->after($after);
        }

        if ($level !== null) {
            $query->level($level);
        }

        if ($orderBy !== null) {
            $query->orderBy($orderBy);
        }

        $query->limit($limit);

        if ($offset !== null) {
            $query->offset($offset);
        }

        $entries = array_values($query->all());

        $data = array_map(
            static fn (Entry $entry): array => $entry->toArray(),
            $entries,
        );

        $appliedLimit = $limit ?? 25;
        $hitLimit = count($data) === $appliedLimit;
        $notes = $this->buildNotes($entries, $hitLimit, $appliedLimit, $site);

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }

    /**
     * @param  list<Entry>  $entries
     */
    private function buildNotes(array $entries, bool $hitLimit, int $appliedLimit, ?string $siteFilter): string
    {
        if ($entries === []) {
            return 'No entries matched the given filters. Loosen filters (e.g. status: "any") or call get_sections to see what sections exist.';
        }

        $base = 'Returned '.count($entries).' entry(ies)';
        if ($hitLimit) {
            $base .= " (limit={$appliedLimit} reached; pass offset to paginate)";
        }
        $base .= '. Use get_entry with an id for full details, or upsert_entry/upsert_draft with an id to modify.';

        $siteContext = $this->renderSiteContext($entries, $siteFilter);
        if ($siteContext !== '') {
            $base .= ' '.$siteContext;
        }

        $nonSiteFields = $this->aggregatedNonSiteTranslationFields($entries);
        if ($nonSiteFields !== []) {
            $base .= ' ⚠️ Translation caution — these custom fields are NOT per-site (their value is shared across locales): '
                .implode('; ', $nonSiteFields)
                .'. To make one translatable, call upsert_field with `translationMethod="site"`.';
        }

        return $base;
    }

    /**
     * @param  list<Entry>  $entries
     */
    private function renderSiteContext(array $entries, ?string $siteFilter): string
    {
        // All returned entries share a site when a filter was applied, or
        // default to the primary site when not. Use the first row as the
        // representative.
        $first = $entries[0] ?? null;
        if ($first === null || $first->siteId === null) {
            return '';
        }
        $site = Craft::$app->sites->getSiteById($first->siteId);
        if ($site === null) {
            return '';
        }

        $suffix = $siteFilter === null
            ? ' (the install\'s primary; pass `site=<handle>` to read another locale)'
            : '';

        return sprintf(
            'Returned from site "%s" (id=%d, language="%s")%s.',
            $site->handle ?? '',
            $site->id ?? 0,
            $site->language ?? '',
            $suffix,
        );
    }

    /**
     * Collect a deduplicated list of custom fields across every returned
     * entry whose translationMethod is not "site" — i.e. fields shared
     * across locales.
     *
     * @param  list<Entry>  $entries
     * @return list<string>
     */
    private function aggregatedNonSiteTranslationFields(array $entries): array
    {
        $seen = [];
        foreach ($entries as $entry) {
            $type = $entry->getType();
            $layout = $type->getFieldLayout();
            foreach ($layout->getCustomFields() as $field) {
                if (! $field instanceof Field) {
                    continue;
                }
                $method = $field->translationMethod;
                if ($method === Field::TRANSLATION_METHOD_SITE) {
                    continue;
                }
                $handle = $field->handle;
                if ($handle === '' || $handle === null || isset($seen[$handle])) {
                    continue;
                }
                $seen[$handle] = "{$handle} (translationMethod=\"{$method}\")";
            }
        }

        return array_values($seen);
    }
}
