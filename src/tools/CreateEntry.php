<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Entry;
use markhuot\craftai\attributes\Description;

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
        string $section,
        #[Description('Entry title')]
        string $title,
        #[Description('Entry type handle (defaults to the section\'s first entry type)')]
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
        ?string $site = null,
        #[Description('Custom field values keyed by field handle (e.g. {"body": "Hello", "summary": "..."})')]
        ?array $fields = null,
    ): array|ToolOutput {
        $sectionModel = Craft::$app->entries->getSectionByHandle($section);

        if ($sectionModel === null) {
            return new ToolOutput("No section found with handle \"{$section}\".", isError: true);
        }

        $entryType = null;
        if ($type !== null) {
            foreach ($sectionModel->getEntryTypes() as $candidate) {
                if ($candidate->handle === $type) {
                    $entryType = $candidate;
                    break;
                }
            }

            if ($entryType === null) {
                return new ToolOutput("No entry type \"{$type}\" found in section \"{$section}\".", isError: true);
            }
        } else {
            $entryType = $sectionModel->getEntryTypes()[0];
        }

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
            $siteModel = Craft::$app->sites->getSiteByHandle($site);
            if ($siteModel === null) {
                return new ToolOutput("No site found with handle \"{$site}\".", isError: true);
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
