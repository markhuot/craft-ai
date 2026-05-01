<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
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
     * @return array<string, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Section handle to create the entry in (e.g. "news", "blog")')]
        #[Validate('required')]
        #[Validate(ExistingSection::class)]
        string $section,
        #[Description('Entry title')]
        #[Validate('required')]
        #[Validate('string', max: 255)]
        string $title,
        #[Description('Entry type handle (defaults to the section\'s first entry type)')]
        #[Validate(ExistingEntryType::class, inSection: 'section')]
        ?string $type = null,
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
        $sectionModel = Craft::$app->entries->getSectionByHandle($section);
        $entryType = $this->resolveEntryType($sectionModel, $type);

        $entry = new Entry();
        $entry->sectionId = $sectionModel->id;
        $entry->typeId = $entryType->id;
        $entry->title = $title;
        $entry->enabled = $enabled;

        if ($slug !== null) {
            $entry->slug = $slug;
        }

        if ($authorId !== null) {
            $entry->authorId = $authorId;
        } elseif (($user = Craft::$app->user->getIdentity()) !== null) {
            $entry->authorId = $user->id;
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
            $entry->siteId = Craft::$app->sites->getSiteByHandle($site)->id;
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

    private function resolveEntryType($section, ?string $type)
    {
        if ($type === null) {
            return $section->getEntryTypes()[0];
        }

        foreach ($section->getEntryTypes() as $candidate) {
            if ($candidate->handle === $type) {
                return $candidate;
            }
        }

        return $section->getEntryTypes()[0];
    }
}
