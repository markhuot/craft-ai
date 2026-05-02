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
use markhuot\craftai\validators\ExistingEntryTypes;

/**
 * Create a new content section in the CMS. Returns the created section's
 * full details on success, or an error describing why it could not be saved.
 *
 * Sections must be assigned at least one entry type. Provide existing entry
 * type handles or IDs via the `entryTypes` argument.
 */
class CreateSection extends Tool
{
    /**
     * @param  list<EntryType>  $entryTypes  Existing entry types to assign (resolved by binder)
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Display name for the section (e.g. "News")')]
        #[Validate('string', max: 255)]
        string $name,
        #[Description('Section handle used in templates and queries (e.g. "news")')]
        #[Validate('string', max: 255)]
        string $handle,
        #[Description('Section type: "single", "channel", or "structure"')]
        #[Validate('in', range: ['single', 'channel', 'structure'])]
        string $type,
        #[Description('Existing entry type handles or IDs to assign to this section (at least one required)')]
        #[Validate(ExistingEntryTypes::class)]
        #[Bind(EntryTypesBinder::class)]
        array $entryTypes,
        #[Description('URI format for entry URLs (e.g. "news/{slug}"). Omit to disable URLs.')]
        ?string $uriFormat = null,
        #[Description('Template path to render entries (e.g. "news/_entry")')]
        ?string $template = null,
        #[Description('Whether new entries should be enabled by default (default true)')]
        bool $enabledByDefault = true,
        #[Description('Maximum nesting levels for structure sections')]
        ?int $maxLevels = null,
        #[Description('Propagation method: "none", "siteGroup", "language", "custom", or "all" (default)')]
        #[Validate('in', range: ['none', 'siteGroup', 'language', 'custom', 'all'])]
        ?string $propagationMethod = null,
        #[Description('Whether to enable draft versioning (default true)')]
        bool $enableVersioning = true,
    ): array|ToolOutput {
        $siteSettings = [];
        foreach (Craft::$app->sites->getAllSites() as $site) {
            $siteSettings[] = new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => $enabledByDefault,
                'hasUrls' => $uriFormat !== null,
                'uriFormat' => $uriFormat,
                'template' => $template,
            ]);
        }

        $section = new Section([
            'name' => $name,
            'handle' => $handle,
            'type' => $type,
            'enableVersioning' => $enableVersioning,
            'siteSettings' => $siteSettings,
        ]);

        if ($maxLevels !== null) {
            $section->maxLevels = $maxLevels;
        }

        if ($propagationMethod !== null) {
            $section->propagationMethod = \craft\enums\PropagationMethod::from($propagationMethod);
        }

        $section->setEntryTypes($entryTypes);

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
