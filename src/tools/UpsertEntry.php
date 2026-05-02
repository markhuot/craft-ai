<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Entry as EntryBinder;
use markhuot\craftai\binders\EntryType as EntryTypeBinder;
use markhuot\craftai\binders\Section as SectionBinder;
use markhuot\craftai\binders\Site as SiteBinder;
use markhuot\craftai\validators\ExistingEntry;
use markhuot\craftai\validators\ExistingEntryType;
use markhuot\craftai\validators\ExistingSection;
use markhuot\craftai\validators\ExistingSite;

/**
 * Create or update a content entry. Pass `id` to update an existing entry;
 * omit it to create a new one (in which case `section` and `title` are
 * required). Returns the saved entry's full details on success.
 */
class UpsertEntry extends Tool
{
    /**
     * @param  array<string, mixed>|null  $fields  Custom field values keyed by field handle
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Existing entry ID to update. Omit to create a new entry.')]
        #[Validate(ExistingEntry::class)]
        #[Bind(EntryBinder::class)]
        Entry|int|null $id = null,
        #[Description('Section handle to create the entry in (e.g. "news", "blog"). Required when creating; ignored when updating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate(ExistingSection::class)]
        #[Bind(SectionBinder::class)]
        Section|string|int|null $section = null,
        #[Description('Entry title. Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $title = null,
        #[Description('Entry type handle (defaults to the section\'s first entry type on create)')]
        #[Validate(ExistingEntryType::class, inSection: 'section')]
        #[Bind(EntryTypeBinder::class, inSection: 'section', defaultToFirst: true)]
        EntryType|string|int|null $type = null,
        #[Description('URL slug (auto-generated from title on create if omitted)')]
        ?string $slug = null,
        #[Description('Author user ID (defaults to the current user on create)')]
        ?int $authorId = null,
        #[Description('Post date (e.g. "2024-01-01 12:00:00", "now")')]
        ?string $postDate = null,
        #[Description('Expiry date (e.g. "2024-12-31 23:59:59")')]
        ?string $expiryDate = null,
        #[Description('Whether the entry is enabled')]
        ?bool $enabled = null,
        #[Description('Site handle for multi-site installs (e.g. "english", "french")')]
        #[Validate(ExistingSite::class)]
        #[Bind(SiteBinder::class)]
        Site|string|int|null $site = null,
        #[Description('Custom field values keyed by field handle (e.g. {"body": "Hello", "summary": "..."})')]
        ?array $fields = null,
    ): array|ToolOutput {
        $isUpdate = $id instanceof Entry;

        if ($isUpdate) {
            $entry = $id;
        } else {
            assert($section instanceof Section);
            assert($title !== null);
            assert($type instanceof EntryType);

            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId = $type->id;

            if ($authorId !== null) {
                $entry->authorId = $authorId;
            } elseif (($user = Craft::$app->user->getIdentity()) !== null) {
                $userId = $user->getId();
                $entry->authorId = is_numeric($userId) ? (int) $userId : null;
            }
        }

        if ($isUpdate && $type instanceof EntryType && $type->id !== null) {
            $entry->typeId = $type->id;
        }

        if ($title !== null) {
            $entry->title = $title;
        }

        if ($slug !== null) {
            $entry->slug = $slug;
        }

        if ($authorId !== null && $isUpdate) {
            $entry->authorId = $authorId;
        }

        if ($postDate !== null) {
            $entry->postDate = \DateTime::createFromFormat('Y-m-d H:i:s', $postDate)
                ?: new \DateTime($postDate);
        }

        if ($expiryDate !== null) {
            $entry->expiryDate = \DateTime::createFromFormat('Y-m-d H:i:s', $expiryDate)
                ?: new \DateTime($expiryDate);
        }

        if ($enabled !== null) {
            $entry->enabled = $enabled;
        } elseif (! $isUpdate) {
            $entry->enabled = true;
        }

        if ($site instanceof Site) {
            $entry->siteId = $site->id;
        }

        if ($fields !== null) {
            $entry->setFieldValues($fields);
        }

        if (! Craft::$app->elements->saveElement($entry)) {
            $errors = $entry->getErrorSummary(true);

            return new ToolOutput(
                'Could not save entry: '.implode('; ', $errors),
                isError: true,
            );
        }

        return $entry->toArray();
    }
}
