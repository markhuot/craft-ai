<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\Site;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;

/**
 * List the Craft sites configured for this install. Each site has a language
 * tied to it (e.g. "en-US", "es-MX", "fr"); content is stored per-site so
 * translating an entry means saving its translated values against the
 * target-language site. The agent can use this to discover which locales
 * are available before producing a localized draft.
 *
 * Disabled sites are excluded by default — pass `includeDisabled=true` to
 * see them.
 */
class GetSites extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<string, mixed>>}
     */
    public function __invoke(
        #[Description('Filter to sites that use this exact language code (e.g. "es-MX"). Omit to return all sites.')]
        #[Validate('string')]
        ?string $language = null,
        #[Description('Filter to sites in this site group, identified by group name or numeric ID.')]
        ?string $group = null,
        #[Description('Include sites that are currently disabled. Defaults to false.')]
        ?bool $includeDisabled = null,
    ): array {
        $withDisabled = $includeDisabled === true;
        $sites = Craft::$app->sites->getAllSites($withDisabled);

        $groupId = $this->resolveGroupId($group);

        $filtered = [];
        foreach ($sites as $site) {
            if ($language !== null && $language !== '' && $site->language !== $language) {
                continue;
            }
            if ($groupId !== null && $site->groupId !== $groupId) {
                continue;
            }
            $filtered[] = $site;
        }

        $data = array_map(
            fn (Site $site): array => $this->serializeSite($site),
            $filtered,
        );

        $notes = $this->buildNotes($data, $language, $group);

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
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

    private function resolveGroupId(?string $group): ?int
    {
        if ($group === null || $group === '') {
            return null;
        }

        if (ctype_digit($group)) {
            $found = Craft::$app->sites->getGroupById((int) $group);

            return $found?->id;
        }

        foreach (Craft::$app->sites->getAllGroups() as $candidate) {
            if ($candidate->getName() === $group) {
                return $candidate->id;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $data
     */
    private function buildNotes(array $data, ?string $language, ?string $group): string
    {
        if ($data === []) {
            if ($language !== null && $language !== '') {
                return "No sites configured for language \"{$language}\". Use upsert_site to add one.";
            }
            if ($group !== null && $group !== '') {
                return "No sites in group \"{$group}\". Use upsert_site to add one.";
            }

            return 'No sites are configured. Use upsert_site to add one.';
        }

        $count = count($data);
        $plural = $count === 1 ? 'site' : 'sites';

        return "Returned {$count} {$plural}. Each site's `language` field drives which locale its content is stored under — to translate an entry, pass that site's handle or id as the `site` argument to upsert_entry or upsert_draft so the translated values save against the right locale.";
    }
}
