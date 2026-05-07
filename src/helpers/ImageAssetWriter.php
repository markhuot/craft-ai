<?php

namespace markhuot\craftai\helpers;

use Craft;
use craft\elements\Asset;
use craft\helpers\StringHelper;
use craft\models\Volume;
use craft\models\VolumeFolder;
use markhuot\craftai\agent\providers\GeneratedImage;
use markhuot\craftai\tools\ToolOutput;

/**
 * Shared logic for the per-model image generation tools (gpt-image-1,
 * Nano Banana, etc.). Each tool builds its own provider-native request body
 * and gets back raw bytes; this helper handles the next step that's identical
 * across providers — writing the bytes to a Craft asset volume and returning
 * a {@see ToolOutput} with a structured `images: [{...}]` payload so the
 * agent can reference the asset later by id or url and render the image in
 * its response however it likes.
 *
 * Returns a single ToolOutput so tools can `return ImageAssetWriter::save(...)`
 * directly on either the success or error path.
 *
 * The output is shaped as a list — `{"images": [...]}` — even though every
 * current provider returns a single image. This leaves room for batched
 * generation (e.g. Imagen's `sampleCount`) without rewriting the contract.
 */
class ImageAssetWriter
{
    public static function save(
        GeneratedImage $generated,
        string $prompt,
        Volume $volume,
        ?string $folder = null,
        ?string $filename = null,
        ?string $title = null,
        ?string $alt = null,
    ): ToolOutput {
        $folderModel = self::resolveFolder($volume, $folder);
        if ($folderModel === null) {
            return new ToolOutput(
                "Could not save generated image: folder \"{$folder}\" not found in volume \"{$volume->handle}\".",
                isError: true,
            );
        }

        $tempFile = tempnam(Craft::$app->path->getTempPath(), 'craftai-genimage');
        if ($tempFile === false) {
            return new ToolOutput('Could not save generated image: failed to create a temporary file.', isError: true);
        }

        if (file_put_contents($tempFile, $generated->bytes) === false) {
            @unlink($tempFile);

            return new ToolOutput('Could not save generated image: failed to write the temporary file.', isError: true);
        }

        $extension = $generated->extension();
        $finalFilename = self::buildFilename($filename, $prompt, $extension);

        $asset = new Asset();
        $asset->tempFilePath = $tempFile;
        $asset->filename = $finalFilename;
        $asset->newFolderId = $folderModel->id;
        $asset->setScenario(Asset::SCENARIO_CREATE);
        $asset->title = $title ?? StringHelper::truncate($prompt, 100, '…');

        if ($alt !== null) {
            $asset->alt = $alt;
        }

        if (! Craft::$app->elements->saveElement($asset)) {
            $errors = $asset->getErrorSummary(true);

            return new ToolOutput(
                'Could not save generated image: '.implode('; ', $errors),
                isError: true,
            );
        }

        $url = $asset->getUrl();

        /** @var array<string, mixed> $imageEntry */
        $imageEntry = [
            'id' => $asset->id,
            'url' => $url,
            'filename' => $asset->filename,
            'mimeType' => $asset->getMimeType(),
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
        ];
        if ($generated->revisedPrompt !== null) {
            $imageEntry['revisedPrompt'] = $generated->revisedPrompt;
        }

        $payload = ['images' => [$imageEntry]];
        $text = json_encode($payload, JSON_THROW_ON_ERROR);

        return new ToolOutput(text: $text);
    }

    private static function resolveFolder(Volume $volume, ?string $path): ?VolumeFolder
    {
        if ($volume->id === null) {
            return null;
        }

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

    private static function buildFilename(?string $filename, string $prompt, string $extension): string
    {
        if ($filename !== null && $filename !== '') {
            $stem = pathinfo($filename, PATHINFO_FILENAME);
            $providedExt = pathinfo($filename, PATHINFO_EXTENSION);

            return $stem.'.'.($providedExt !== '' ? $providedExt : $extension);
        }

        $slug = StringHelper::slugify(StringHelper::truncate($prompt, 60, ''));
        if ($slug === '') {
            $slug = 'generated-image';
        }
        $stamp = date('Ymd-His');

        return "{$slug}-{$stamp}.{$extension}";
    }
}
