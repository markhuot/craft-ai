<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\Site;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Site as SiteBinder;
use markhuot\craftai\validators\ExistingSite;

/**
 * Create or update a Craft site. Pass `id` (handle or numeric ID) to update
 * an existing site; omit it to create a new one — in which case `name`,
 * `handle`, and `language` are required.
 *
 * Sites are the locale boundary in Craft: each site has exactly one
 * `language` (e.g. "en-US", "es-MX") and content fields store one value
 * per site. To translate an entry, create or pick the target-language
 * site here, then call `upsert_entry` (or `upsert_draft`) with that
 * site's handle or id so the translated values save against the right
 * locale instead of overwriting the source.
 */
class UpsertSite extends Tool
{
    /**
     * @return array{_notes: string, data: array<string, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Existing site ID (number) or handle (string) to update. Omit to create a new site.')]
        #[Validate(ExistingSite::class)]
        #[Bind(SiteBinder::class)]
        Site|string|int|null $id = null,
        #[Description('Display name (e.g. "Spanish (Mexico)"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $name = null,
        #[Description('Site handle used in templates, queries, and as the `site` argument to upsert_entry. Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $handle = null,
        #[Description('IETF language tag this site\'s content is stored under (e.g. "en-US", "es-MX", "fr", "ja"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 32)]
        ?string $language = null,
        #[Description('Whether this site should become the primary site. Setting true on a non-primary site automatically demotes the current primary.')]
        ?bool $primary = null,
        #[Description('Whether the site is enabled (visible to the front end and editable in the CP). Defaults to true on create.')]
        ?bool $enabled = null,
        #[Description('Whether entries in this site have URLs. Defaults to true on create.')]
        ?bool $hasUrls = null,
        #[Description('Base URL for the site (e.g. "https://example.com/es/"). Supports Craft\'s `@alias` and `$ENV_VAR` syntax. Pass an empty string to clear it.')]
        ?string $baseUrl = null,
        #[Description('Site group, identified by group name or numeric ID. Defaults to the first existing group on create. Required only if you have multiple groups and want to pin this site to a specific one.')]
        ?string $group = null,
    ): array|ToolOutput {
        $isUpdate = $id instanceof Site;

        if ($isUpdate) {
            $site = $id;
        } else {
            assert($name !== null);
            assert($handle !== null);
            assert($language !== null);

            $site = new Site();
        }

        if ($name !== null) {
            $site->setName($name);
        }

        if ($handle !== null) {
            $site->handle = $handle;
        }

        if ($language !== null) {
            $site->setLanguage($language);
        }

        if ($primary !== null) {
            $site->primary = $primary;
        }

        if ($enabled !== null) {
            $site->setEnabled($enabled);
        } elseif (! $isUpdate) {
            $site->setEnabled(true);
        }

        if ($hasUrls !== null) {
            $site->hasUrls = $hasUrls;
        } elseif (! $isUpdate) {
            $site->hasUrls = true;
        }

        if ($baseUrl !== null) {
            $site->setBaseUrl($baseUrl === '' ? null : $baseUrl);
        }

        if ($group !== null && $group !== '') {
            $resolvedGroupId = $this->resolveGroupId($group);
            if ($resolvedGroupId === null) {
                return new ToolOutput(
                    "No site group found with name or ID \"{$group}\".",
                    isError: true,
                );
            }
            $site->groupId = $resolvedGroupId;
        } elseif (! $isUpdate && $site->groupId === null) {
            $defaultGroup = Craft::$app->sites->getAllGroups()[0] ?? null;
            if ($defaultGroup === null) {
                return new ToolOutput(
                    'Cannot create a site: no site groups exist. Create a site group first.',
                    isError: true,
                );
            }
            $site->groupId = $defaultGroup->id;
        }

        if (! Craft::$app->sites->saveSite($site)) {
            $errors = $site->getErrorSummary(true);

            return new ToolOutput(
                'Could not save site: '.implode('; ', $errors),
                isError: true,
            );
        }

        // saveSite() runs refreshSites(), but that only refreshes Craft's
        // `getIsMultiSite()` memo — NOT the separate `withTrashed` memo that
        // ElementQuery::beforePrepare() consults to decide whether to apply a
        // `siteId` filter. On an install that was single-site when the first
        // element query ran this request, that memo is stuck at `false`, so
        // every subsequent siteId-scoped query silently drops the filter and
        // returns the primary-site row. That's exactly the /translate flow:
        // create a locale here, then translate into it within the same
        // request. Force the memo to recompute so reads/writes against the
        // freshly created site actually target it.
        Craft::$app->getIsMultiSite(true, true);

        $verb = $isUpdate ? 'Updated' : 'Created';
        $notes = sprintf(
            '%s site id=%d (handle="%s", language="%s"). To translate content into this locale, call upsert_entry or upsert_draft with `site="%s"` so the translated field values save against this site instead of overwriting the source.',
            $verb,
            $site->id,
            $site->handle,
            $site->language,
            $site->handle,
        );

        if (! $isUpdate) {
            $sectionsMissing = $this->sectionsNotEnabledForSite($site->id);
            if ($sectionsMissing !== []) {
                $notes .= sprintf(
                    ' ⚠️ Existing sections were configured before this site existed, so they do not yet enable it: [%s]. Until you re-call upsert_section for each with the full `sites` list including "%s", entries cannot be created or translated on this site.',
                    implode(', ', $sectionsMissing),
                    $site->handle,
                );
            }
        }

        return [
            '_notes' => $notes,
            'data' => $this->serializeSite($site),
        ];
    }

    /**
     * Return the handles of every section that has no site_settings row
     * for the given site ID. Used to warn the agent that creating a site
     * is only half the work — each affected section also needs the new
     * site added to its enablement list.
     *
     * @return list<string>
     */
    private function sectionsNotEnabledForSite(?int $siteId): array
    {
        if ($siteId === null) {
            return [];
        }

        $missing = [];
        foreach (Craft::$app->entries->getAllSections() as $section) {
            if (! is_string($section->handle) || $section->handle === '') {
                continue;
            }
            $enabledForSite = false;
            foreach ($section->getSiteSettings() as $row) {
                if ((int) $row->siteId === $siteId) {
                    $enabledForSite = true;
                    break;
                }
            }
            if (! $enabledForSite) {
                $missing[] = $section->handle;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSite(Site $site): array
    {
        $group = $site->groupId !== null
            ? Craft::$app->sites->getGroupById($site->groupId)
            : null;

        return [
            'id' => $site->id,
            'handle' => $site->handle,
            'name' => $site->getName(),
            'language' => $site->language,
            'primary' => $site->primary,
            'enabled' => $site->getEnabled(),
            'hasUrls' => $site->hasUrls,
            'baseUrl' => $site->getBaseUrl(),
            'groupId' => $site->groupId,
            'groupName' => $group?->getName(),
            'sortOrder' => $site->sortOrder,
        ];
    }

    private function resolveGroupId(string $group): ?int
    {
        if (ctype_digit($group)) {
            return Craft::$app->sites->getGroupById((int) $group)?->id;
        }

        foreach (Craft::$app->sites->getAllGroups() as $candidate) {
            if ($candidate->getName() === $group) {
                return $candidate->id;
            }
        }

        return null;
    }
}
