<?php

namespace markhuot\craftai\tools;

use craft\elements\Asset;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Asset as AssetBinder;
use markhuot\craftai\validators\ExistingAsset;

/**
 * Retrieve a single asset by its `id`. Returns the asset's metadata
 * (filename, kind, mime type, size, dimensions) plus a public URL the
 * caller can use to download or inspect the file. The user's chat input
 * may attach assets — when that happens, the tool surfaces an annotation
 * listing each attached asset's id, and you can call this tool to look up
 * the details on demand.
 */
class GetAsset extends Tool
{
    /**
     * @return array<array-key, mixed>
     */
    public function __invoke(
        #[Description('The asset ID to look up.')]
        #[Validate(ExistingAsset::class)]
        #[Bind(AssetBinder::class)]
        Asset|int $id,
    ): array {
        if (! $id instanceof Asset) {
            throw new \LogicException('Asset was not bound before invocation.');
        }

        $data = $id->toArray();
        $data['mimeType'] = $id->getMimeType();
        $data['url'] = $id->getUrl();

        return $data;
    }
}
