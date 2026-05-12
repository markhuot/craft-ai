<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\models\Volume;

/**
 * List all asset volumes defined in the CMS. Returns each volume's ID, UID,
 * name, handle, filesystem handle, and subpath — useful for discovering
 * available volumes before creating an asset with `upsert_asset`.
 */
class GetVolumes extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<array-key, mixed>>}
     */
    public function __invoke(): array
    {
        $data = array_values(array_map(
            static fn (Volume $volume): array => $volume->toArray(),
            Craft::$app->volumes->getAllVolumes(),
        ));

        $notes = $data === []
            ? 'No asset volumes are configured. Create one in CP Settings -> Assets before calling upsert_asset.'
            : 'Returned '.count($data).' volume(s). Pass a volume `handle` to upsert_asset when uploading new files.';

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
