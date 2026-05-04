<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Asset;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;

/**
 * Delete one or more assets by ID. By default assets are soft-deleted and can
 * be restored from the trash; pass `hardDelete` to permanently remove them.
 * Returns a per-ID result map.
 */
#[ToolAttribute(annotations: [
    'title' => 'Delete assets',
    'destructiveHint' => true,
    'idempotentHint' => true,
    'readOnlyHint' => false,
    'openWorldHint' => false,
])]
class DeleteAssets extends Tool
{
    /**
     * @param  list<int>  $ids
     * @return array<array-key, mixed>|ToolOutput
     */
    public function __invoke(
        #[Description('Asset IDs to delete.')]
        #[Validate('required')]
        array $ids = [],
        #[Description('When true, permanently delete instead of moving to trash. Defaults to false.')]
        ?bool $hardDelete = null,
    ): array|ToolOutput {
        $results = [];
        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                $results[(string) $id] = ['deleted' => false, 'error' => 'ID must be a numeric value.'];

                continue;
            }

            $intId = (int) $id;
            $asset = Asset::find()->id($intId)->status(null)->one();
            if (! $asset instanceof Asset) {
                $results[(string) $intId] = ['deleted' => false, 'error' => "No asset found with ID {$intId}."];

                continue;
            }

            try {
                $deleted = Craft::$app->elements->deleteElement($asset, $hardDelete ?? false);
                $results[(string) $intId] = $deleted
                    ? ['deleted' => true]
                    : ['deleted' => false, 'error' => 'Craft refused to delete the asset.'];
            } catch (\Throwable $e) {
                $results[(string) $intId] = ['deleted' => false, 'error' => $e->getMessage()];
            }
        }

        return ['results' => $results];
    }
}
