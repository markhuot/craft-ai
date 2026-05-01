<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\EntryType as EntryTypeBinder;
use markhuot\craftai\binders\Section as SectionBinder;
use markhuot\craftai\validators\ExistingEntryType;
use markhuot\craftai\validators\ExistingSection;
use markhuot\craftai\validators\ExistingSite;

/**
 * Create a new content entry in the CMS. Returns the created entry's full
 * details on success, or an error describing why the entry could not be saved.
 */
class CreateEntry extends Tool
{
    /**
     * @param  array<string, mixed>|null  $fields  Custom field values keyed by field handle
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Section handle to create the entry in (e.g. "news", "blog")')]
        #[Validate(ExistingSection::class)]
        #[Bind(SectionBinder::class)]
        Section|string|int $section,
        #[Description('Entry title')]
        #[Validate('string', max: 255)]
        string $title,
        #[Description('Entry type handle (defaults to the section\'s first entry type)')]
        #[Validate(ExistingEntryType::class, inSection: 'section')]
        #[Bind(EntryTypeBinder::class, inSection: 'section')]
        EntryType|string|int|null $type = null,
        #[Description('URL slug (auto-generated from title if omitted)')]
        ?string $slug = null,
        #[Description('Author user ID (defaults to the current user)')]
        ?int $authorId = null,
        #[Description('Post date (e.g. "2024-01-01 12:00:00", "now")')]
        ?string $postDate = null,
        #[Description('Expiry date (e.g. "2024-12-31 23:59:59")')]
        ?string $expiryDate = null,
        #[Description('Whether the entry is enabled (default true)')]
        bool $enabled = true,
        #[Description('Site handle for multi-site installs (e.g. "english", "french")')]
        #[Validate(ExistingSite::class)]
        ?string $site = null,
        #[Description('Custom field values keyed by field handle (e.g. {"body": "Hello", "summary": "..."})')]
        ?array $fields = null,
    ): array|ToolOutput {
        if (! $section instanceof Section) {
            return new ToolOutput("Unknown section: {$section}.", isError: true);
        }

        $entryType = $type ?? $section->getEntryTypes()[0];

        if (! $entryType instanceof EntryType) {
            return new ToolOutput("Unknown entry type: {$type}.", isError: true);
        }

        if ($entryType->id === null || $section->id === null) {
            return new ToolOutput('Section or entry type is missing an ID.', isError: true);
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId = $entryType->id;
        $entry->title = $title;
        $entry->enabled = $enabled;

        if ($slug !== null) {
            $entry->slug = $slug;
        }

        if ($authorId !== null) {
            $entry->authorId = $authorId;
        } elseif (($user = Craft::$app->user->getIdentity()) !== null) {
            $userId = $user->getId();
            $entry->authorId = is_numeric($userId) ? (int) $userId : null;
        }

        if ($postDate !== null) {
            $entry->postDate = \DateTime::createFromFormat('Y-m-d H:i:s', $postDate)
                ?: new \DateTime($postDate);
        }

        if ($expiryDate !== null) {
            $entry->expiryDate = \DateTime::createFromFormat('Y-m-d H:i:s', $expiryDate)
                ?: new \DateTime($expiryDate);
        }

        if ($site !== null) {
            $siteModel = Craft::$app->sites->getSiteByHandle($site);
            if ($siteModel === null) {
                return new ToolOutput("Unknown site: {$site}.", isError: true);
            }
            $entry->siteId = $siteModel->id;
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
