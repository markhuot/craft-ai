<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use craft\models\VolumeFolder;
use markhuot\craftai\agent\ToolContext;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Asset as AssetBinder;
use markhuot\craftai\binders\Volume as VolumeBinder;
use markhuot\craftai\helpers\PreviewSuggestion;
use markhuot\craftai\validators\ExistingAsset;
use markhuot\craftai\validators\ExistingVolume;

/**
 * Create or update an asset. Pass `id` to update an existing asset; omit it to
 * create a new one (in which case `volume`, `filename`, and a file source —
 * `url` or `sourcePath` — are required). Returns the saved asset's full
 * details on success.
 */
class UpsertAsset extends Tool
{
    public function __construct(
        private readonly ToolContext $context = new ToolContext(),
    ) {}

    /**
     * @param  array<string, mixed>|null  $fields  Custom field values keyed by field handle
     * @return array{_notes: string, data: array<array-key, mixed>}|ToolOutput
     */
    public function __invoke(
        #[Description('Existing asset ID to update. Omit to create a new asset.')]
        #[Validate(ExistingAsset::class)]
        #[Bind(AssetBinder::class)]
        Asset|int|null $id = null,
        #[Description('Volume handle or ID (e.g. "uploads"). Required when creating; ignored when updating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate(ExistingVolume::class)]
        #[Bind(VolumeBinder::class)]
        Volume|string|int|null $volume = null,
        #[Description('Folder path within the volume (e.g. "subfolder/nested"). Defaults to the volume root.')]
        ?string $folder = null,
        #[Description('Filename including extension (e.g. "photo.jpg"). Required when creating.')]
        #[Validate('required', whenMissing: 'id')]
        #[Validate('string', max: 255)]
        ?string $filename = null,
        #[Description('URL to download the file from. Required when creating unless `sourcePath` is provided.')]
        ?string $url = null,
        #[Description('Local filesystem path to use as the source. Alternative to `url`.')]
        ?string $sourcePath = null,
        #[Description('Asset title (defaults to filename on create).')]
        #[Validate('string', max: 255)]
        ?string $title = null,
        #[Description('Alt text for accessibility.')]
        ?string $alt = null,
        #[Description('Custom field values keyed by field handle (e.g. {"caption": "Hello"})')]
        ?array $fields = null,
    ): array|ToolOutput {
        $isUpdate = $id instanceof Asset;

        if ($isUpdate) {
            $asset = $id;
        } else {
            assert($volume instanceof Volume);
            assert($filename !== null);

            if (($url === null || $url === '') && ($sourcePath === null || $sourcePath === '')) {
                return new ToolOutput(
                    'Could not save asset: a file source is required. Provide either `url` or `sourcePath`.',
                    isError: true,
                );
            }

            $folderModel = $this->resolveFolder($volume, $folder);
            if ($folderModel === null) {
                return new ToolOutput(
                    "Could not save asset: folder \"{$folder}\" not found in volume \"{$volume->handle}\".",
                    isError: true,
                );
            }

            $tempFile = tempnam(Craft::$app->path->getTempPath(), 'craftai-asset');
            if ($tempFile === false) {
                return new ToolOutput('Could not save asset: failed to create a temporary file.', isError: true);
            }

            if ($url !== null && $url !== '') {
                $content = @file_get_contents($url);
                if ($content === false) {
                    @unlink($tempFile);

                    return new ToolOutput("Could not download asset from URL: {$url}", isError: true);
                }
                file_put_contents($tempFile, $content);
            } else {
                if (! is_file($sourcePath)) {
                    @unlink($tempFile);

                    return new ToolOutput("Source file does not exist: {$sourcePath}", isError: true);
                }
                copy($sourcePath, $tempFile);
            }

            $asset = new Asset();
            $asset->tempFilePath = $tempFile;
            $asset->filename = $filename;
            $asset->newFolderId = $folderModel->id;
            $asset->setScenario(Asset::SCENARIO_CREATE);
        }

        if ($isUpdate && $filename !== null) {
            $asset->newFilename = $filename;
        }

        if ($title !== null) {
            $asset->title = $title;
        }

        if ($alt !== null) {
            $asset->alt = $alt;
        }

        if ($fields !== null) {
            $asset->setFieldValues($fields);
        }

        if (! Craft::$app->elements->saveElement($asset)) {
            $errors = $asset->getErrorSummary(true);

            return new ToolOutput(
                'Could not save asset: '.implode('; ', $errors),
                isError: true,
            );
        }

        $url = $asset->getUrl();
        $data = $asset->toArray();
        $data['url'] = $url;

        $notes = sprintf(
            '%s asset id=%d. Use get_asset to fetch the saved record, or upsert_field_layout_element to attach a new asset field to an entry type.',
            $isUpdate ? 'Updated' : 'Created',
            $asset->id,
        );

        return [
            '_notes' => $notes,
            'data' => PreviewSuggestion::wrap($data, $url, 'asset', $this->context),
        ];
    }

    private function resolveFolder(Volume $volume, ?string $path): ?VolumeFolder
    {
        $assets = Craft::$app->assets;
        $root = $assets->getRootFolderByVolumeId($volume->id);

        if ($root === null || $path === null || $path === '' || $path === '/') {
            return $root;
        }

        $normalized = trim($path, '/').'/';

        return $assets->findFolder([
            'volumeId' => $volume->id,
            'path' => $normalized,
        ]);
    }
}
