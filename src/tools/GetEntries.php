<?php

namespace markhuot\craftai\tools;

use craft\elements\Entry;
use markhuot\craftai\attributes\Description;

/**
 * Search for content entries in the CMS. Returns a list of entries matching
 * the given filters. All parameters are optional and can be combined.
 *
 * Each result includes the entry's ID, title, status, section, URL, and custom
 * field values. Results default to 25 — use limit and offset to paginate.
 */
class GetEntries extends Tool
{
    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(
        #[Description('Full-text search query (e.g. "pricing page")')]
        ?string $search = null,
        #[Description('Section handle to filter by (e.g. "news", "blog")')]
        ?string $section = null,
        #[Description('Entry type handle to filter by (e.g. "article", "page")')]
        ?string $type = null,
        #[Description('Status filter: "live" (default), "pending", "expired", "disabled", or "any" for all')]
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

        return $query->collect()
            ->map(fn (Entry $entry) => $entry->toArray())
            ->all();
    }
}
