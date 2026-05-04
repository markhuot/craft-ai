<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\EntryTypes as EntryTypesBinder;
use markhuot\craftai\binders\Section as SectionBinder;
use markhuot\craftai\validators\ExistingEntryTypes;
use markhuot\craftai\validators\ExistingSection;

/**
 * Create or update a content section in the CMS. Pass `id` to update an
 * existing section; omit it to create a new one (in which case `name`,
 * `handle`, `type`, and `entryTypes` are required). Returns the saved
 * section's full details on success.
 */
class UpsertSection extends Tool
{
    /**
     * @param  list<EntryType>|null  $entryTypes  Existing entry types to assign (resolved by binder)
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Existing section ID or handle to update. Omit to create a new section.')]
        #[Validate(ExistingSection::class)]
        #[Bind(SectionBinder::class)]
        Section|string|int|null $id = null,
        #[Description('Display name for the section (e.g. "News"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $name = null,
        #[Description('Section handle used in templates and queries (e.g. "news"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $handle = null,
        #[Description('Section type: "single", "channel", or "structure". Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('in', range: ['single', 'channel', 'structure'])]
        ?string $type = null,
        #[Description('Existing entry type handles or IDs to assign to this section. Required when creating; replaces the current set when updating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate(ExistingEntryTypes::class)]
        #[Bind(EntryTypesBinder::class)]
        ?array $entryTypes = null,
        #[Description('URI format for entry URLs (e.g. "news/{slug}"). Pass an empty string to disable URLs.')]
        ?string $uriFormat = null,
        #[Description('Template path to render entries (e.g. "news/_entry")')]
        ?string $template = null,
        #[Description('Whether new entries should be enabled by default (defaults to true on create)')]
        ?bool $enabledByDefault = null,
        #[Description('Maximum nesting levels for structure sections')]
        ?int $maxLevels = null,
        #[Description('Propagation method: "none", "siteGroup", "language", "custom", or "all"')]
        #[Validate('in', range: ['none', 'siteGroup', 'language', 'custom', 'all'])]
        ?string $propagationMethod = null,
        #[Description('Whether to enable draft versioning (defaults to true on create)')]
        ?bool $enableVersioning = null,
    ): array|ToolOutput {
        $isUpdate = $id instanceof Section;

        if ($isUpdate) {
            $section = $id;
        } else {
            assert($name !== null);
            assert($handle !== null);
            assert($type !== null);
            assert(is_array($entryTypes));

            $section = new Section();
        }

        if ($name !== null) {
            $section->name = $name;
        }

        if ($handle !== null) {
            $section->handle = $handle;
        }

        if ($type !== null) {
            $section->type = $type;
        }

        if ($enableVersioning !== null) {
            $section->enableVersioning = $enableVersioning;
        } elseif (! $isUpdate) {
            $section->enableVersioning = true;
        }

        if ($maxLevels !== null) {
            $section->maxLevels = $maxLevels;
        }

        if ($propagationMethod !== null) {
            $section->propagationMethod = \craft\enums\PropagationMethod::from($propagationMethod);
        }

        $hasSiteSettingsChange = $uriFormat !== null || $template !== null || $enabledByDefault !== null;

        if (! $isUpdate) {
            $siteSettings = [];
            foreach (Craft::$app->sites->getAllSites() as $site) {
                $siteSettings[] = new Section_SiteSettings([
                    'siteId' => $site->id,
                    'enabledByDefault' => $enabledByDefault ?? true,
                    'hasUrls' => $uriFormat !== null && $uriFormat !== '',
                    'uriFormat' => $uriFormat !== '' ? $uriFormat : null,
                    'template' => $template,
                ]);
            }
            $section->setSiteSettings($siteSettings);
        } elseif ($hasSiteSettingsChange) {
            foreach ($section->getSiteSettings() as $settings) {
                if ($enabledByDefault !== null) {
                    $settings->enabledByDefault = $enabledByDefault;
                }
                if ($uriFormat !== null) {
                    $settings->hasUrls = $uriFormat !== '';
                    $settings->uriFormat = $uriFormat !== '' ? $uriFormat : null;
                }
                if ($template !== null) {
                    $settings->template = $template;
                }
            }
        }

        if ($entryTypes !== null) {
            $section->setEntryTypes($entryTypes);
        } elseif ($isUpdate && $section->id !== null) {
            $section->setEntryTypes(Craft::$app->entries->getEntryTypesBySectionId($section->id));
        }

        if (! Craft::$app->entries->saveSection($section)) {
            $errors = $section->getErrorSummary(true);

            return new ToolOutput(
                'Could not save section: '.implode('; ', $errors),
                isError: true,
            );
        }

        return $section->toArray();
    }
}
