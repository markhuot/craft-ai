<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\EntryTypes as EntryTypesBinder;
use markhuot\craftai\binders\Section as SectionBinder;
use markhuot\craftai\binders\Sites as SitesBinder;
use markhuot\craftai\validators\ExistingEntryTypes;
use markhuot\craftai\validators\ExistingSection;
use markhuot\craftai\validators\ExistingSites;

/**
 * Create or update a content section in the CMS. Pass `id` to update an
 * existing section; omit it to create a new one (in which case `name`,
 * `handle`, `type`, and `entryTypes` are required).
 *
 * Per-site enablement: a section is only available on sites listed in its
 * `siteSettings`. Pass `sites` to control which sites the section is
 * enabled on. On create, omitting `sites` enables the section for every
 * configured site (the historical default). On update, omitting `sites`
 * leaves the existing enablement alone; passing a list reconciles the
 * section's enabled-site set to exactly that list — adding new rows with
 * the flat-param defaults (uriFormat/template/enabledByDefault), updating
 * shared settings on existing rows, and removing rows for sites no longer
 * in the list. ⚠️ Removing a site drops the section's site-settings row
 * for that site; entries on that site for this section can no longer be
 * edited as part of the section.
 *
 * Returns the saved section's full details plus an `enabledSiteHandles`
 * list summarising which sites it's now available on.
 */
class UpsertSection extends Tool
{
    /**
     * @param  list<EntryType>|null  $entryTypes  Existing entry types to assign (resolved by binder)
     * @param  list<Site>|null  $sites  Sites the section should be enabled on (resolved by binder)
     * @return array{_notes: string, data: array<array-key, mixed>}|ToolOutput
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
        #[Description('URI format for entry URLs (e.g. "news/{slug}"). Applied to every enabled site, including ones being newly enabled via `sites`. Pass an empty string to disable URLs.')]
        ?string $uriFormat = null,
        #[Description('Template path to render entries (e.g. "news/_entry"). Applied to every enabled site, including ones being newly enabled via `sites`.')]
        ?string $template = null,
        #[Description('Whether new entries should be enabled by default. Applied to every enabled site, including ones being newly enabled via `sites`. Defaults to true on create.')]
        ?bool $enabledByDefault = null,
        #[Description('Maximum nesting levels for structure sections')]
        ?int $maxLevels = null,
        #[Description('Propagation method: "none", "siteGroup", "language", "custom", or "all". Set to "none" or "language" to make the section translatable per-site — but remember to also enable the target sites via `sites`, otherwise entries still cannot be created on those sites.')]
        #[Validate('in', range: ['none', 'siteGroup', 'language', 'custom', 'all'])]
        ?string $propagationMethod = null,
        #[Description('Whether to enable draft versioning (defaults to true on create)')]
        ?bool $enableVersioning = null,
        #[Description('Site handles or IDs the section should be enabled on. On create, defaults to every configured site. On update, reconciles the enabled-site set to exactly this list — adds, keeps, or drops site_settings rows as needed. ⚠️ Sites you omit are removed from the section, which orphans their entries.')]
        #[Validate(ExistingSites::class)]
        #[Bind(SitesBinder::class)]
        ?array $sites = null,
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
            // The `in` validator on $type already guarantees one of
            // single/channel/structure here. Craft 6 types Section::$type as a
            // SectionType enum; Craft 5 uses a plain string — assign whichever
            // the installed version expects.
            $section->type = enum_exists(\CraftCms\Cms\Section\Enums\SectionType::class)
                ? \CraftCms\Cms\Section\Enums\SectionType::from($type)
                : $type;
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

        $section->setSiteSettings(
            $this->reconcileSiteSettings($section, $sites, $uriFormat, $template, $enabledByDefault, $isUpdate),
        );

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

        $enabledSiteHandles = $this->enabledSiteHandles($section);
        $missingSiteHandles = $this->sitesNotEnabled($enabledSiteHandles);

        return [
            '_notes' => $this->buildNotes($section, $isUpdate, $enabledSiteHandles, $missingSiteHandles),
            'data' => $section->toArray() + ['enabledSiteHandles' => $enabledSiteHandles],
        ];
    }

    /**
     * Build the section's Section_SiteSettings list based on the desired
     * sites and the flat per-site overrides.
     *
     * When `$sites` is null:
     *   - on create, enable every configured site (historical default);
     *   - on update, leave the existing enablement untouched, applying
     *     flat-param overrides to whatever rows already exist.
     *
     * When `$sites` is a list, the section's enabled-site set becomes
     * exactly that list: existing rows for those sites are preserved and
     * shared overrides applied; missing rows are created from the flat
     * params; rows for omitted sites are dropped.
     *
     * @param  list<Site>|null  $sites
     * @return list<Section_SiteSettings>
     */
    private function reconcileSiteSettings(
        Section $section,
        ?array $sites,
        ?string $uriFormat,
        ?string $template,
        ?bool $enabledByDefault,
        bool $isUpdate,
    ): array {
        $existingByCheck = [];
        if ($isUpdate) {
            foreach ($section->getSiteSettings() as $existing) {
                if ($existing->siteId === null) {
                    continue;
                }
                $existingByCheck[$existing->siteId] = $existing;
            }
        }

        $targetSites = $sites;
        if ($targetSites === null) {
            if (! $isUpdate) {
                $targetSites = array_values(Craft::$app->sites->getAllSites());
            } else {
                $targetSites = [];
                foreach ($existingByCheck as $existing) {
                    $site = Craft::$app->sites->getSiteById((int) $existing->siteId);
                    if ($site !== null) {
                        $targetSites[] = $site;
                    }
                }
            }
        }

        $out = [];
        foreach ($targetSites as $site) {
            $row = $existingByCheck[$site->id] ?? new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => $uriFormat !== null && $uriFormat !== '',
                'uriFormat' => $uriFormat !== '' ? $uriFormat : null,
                'template' => $template,
            ]);

            if ($enabledByDefault !== null) {
                $row->enabledByDefault = $enabledByDefault;
            }

            if ($uriFormat !== null) {
                $row->hasUrls = $uriFormat !== '';
                $row->uriFormat = $uriFormat !== '' ? $uriFormat : null;
            }

            if ($template !== null) {
                $row->template = $template;
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function enabledSiteHandles(Section $section): array
    {
        $handles = [];
        foreach ($section->getSiteSettings() as $row) {
            if ($row->siteId === null) {
                continue;
            }
            $site = Craft::$app->sites->getSiteById((int) $row->siteId);
            if ($site !== null && is_string($site->handle) && $site->handle !== '') {
                $handles[] = $site->handle;
            }
        }

        return $handles;
    }

    /**
     * Sites the install knows about that this section is *not* yet enabled
     * for. Surfaced in the notes when the gap exists so the agent can fix
     * it in the next call.
     *
     * @param  list<string>  $enabledHandles
     * @return list<string>
     */
    private function sitesNotEnabled(array $enabledHandles): array
    {
        $missing = [];
        foreach (Craft::$app->sites->getAllSites() as $site) {
            if (! is_string($site->handle) || $site->handle === '') {
                continue;
            }
            if (! in_array($site->handle, $enabledHandles, true)) {
                $missing[] = $site->handle;
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $enabledHandles
     * @param  list<string>  $missingHandles
     */
    private function buildNotes(
        Section $section,
        bool $isUpdate,
        array $enabledHandles,
        array $missingHandles,
    ): string {
        $verb = $isUpdate ? 'Updated' : 'Created';
        $enabledList = $enabledHandles === [] ? '(none)' : implode(', ', $enabledHandles);

        $primary = sprintf(
            '%s section id=%d (handle="%s", type="%s"). Currently enabled on sites: [%s].',
            $verb,
            $section->id,
            $section->handle,
            $section->type instanceof \BackedEnum ? $section->type->value : $section->type,
            $enabledList,
        );

        if ($missingHandles !== []) {
            $missingList = implode(', ', $missingHandles);
            $primary .= sprintf(
                ' Other configured sites not enabled for this section: [%s]. To make the section translatable into those locales, re-call upsert_section with `sites=[%s]` (include every handle you want enabled — the list is reconciled to exactly what you pass).',
                $missingList,
                implode(', ', array_map(
                    static fn (string $h): string => "\"{$h}\"",
                    array_merge($enabledHandles, $missingHandles),
                )),
            );
        }

        $primary .= sprintf(
            ' Create entries with upsert_entry section="%s", or attach additional entry types via upsert_entry_type then re-call upsert_section.',
            $section->handle,
        );

        return $primary;
    }
}
