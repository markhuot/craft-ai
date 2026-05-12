<?php

namespace markhuot\craftai\tools;

use craft\models\Volume;
use markhuot\craftai\agent\providers\GeminiImageProvider;
use markhuot\craftai\agent\providers\ImageGenerationException;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Volume as VolumeBinder;
use markhuot\craftai\helpers\ImageAssetWriter;
use markhuot\craftai\validators\ExistingVolume;

/**
 * Generate an image with Google's "Nano Banana" model
 * (`gemini-2.5-flash-image`) and save it to a Craft asset volume.
 * Exposes the model's native parameter surface — `aspectRatio` from Gemini's
 * full supported set — so the agent can use the model exactly as Google's
 * docs describe.
 *
 * Nano Banana is a multi-modal image-capable Gemini model: it can also emit
 * a text response alongside the image (via `responseModalities`). For now
 * this tool always requests an image; future expansion could expose the
 * text portion as well, or accept input images for editing/inpainting which
 * is one of Nano Banana's headline capabilities.
 */
#[ToolAttribute(name: 'generate_image_nano_banana')]
class GenerateImageNanoBanana extends Tool
{
    public const KIND = ToolKind::LiveWrite;

    public function __construct(
        private readonly GeminiImageProvider $provider,
    ) {}

    /**
     * @return array{_notes: string, data: array{images: list<array<string, mixed>>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Text prompt describing the image to generate. Nano Banana works well with conversational, descriptive prompts.')]
        #[Validate('string', max: 4000)]
        string $prompt,
        #[Description('Volume handle or ID to save the asset to (e.g. "uploads").')]
        #[Validate('required')]
        #[Validate(ExistingVolume::class)]
        #[Bind(VolumeBinder::class)]
        Volume|string|int|null $volume = null,
        #[Description('Folder path within the volume (e.g. "ai/generated"). Defaults to the volume root.')]
        ?string $folder = null,
        #[Description('Filename for the saved asset (without extension). A slug is generated from the prompt if omitted.')]
        ?string $filename = null,
        #[Description('Asset title (defaults to the prompt, truncated).')]
        #[Validate('string', max: 255)]
        ?string $title = null,
        #[Description('Alt text for accessibility. Strongly recommended — describe the image content for screen readers.')]
        ?string $alt = null,
        #[Description('Aspect ratio. Nano Banana supports 1:1, 2:3, 3:2, 3:4, 4:3, 4:5, 5:4, 9:16, 16:9, and 21:9. Defaults to 1:1.')]
        #[Validate('in', range: ['1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'])]
        ?string $aspectRatio = null,
    ): array|ToolOutput {
        assert($volume instanceof Volume);

        // The docs' canonical request shape is a single user `parts` block
        // with `imageConfig.aspectRatio` under `generationConfig`. We omit
        // `responseModalities` because the default already returns both text
        // and image parts for this model — the response parser ignores the
        // text and consumes the inline image data.
        /** @var array<string, mixed> $body */
        $body = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
        ];

        if ($aspectRatio !== null) {
            $body['generationConfig'] = ['imageConfig' => ['aspectRatio' => $aspectRatio]];
        }

        try {
            $generated = $this->provider->generate($body);
        } catch (ImageGenerationException $e) {
            return new ToolOutput($e->getMessage(), isError: true);
        }

        $saved = ImageAssetWriter::save(
            generated: $generated,
            prompt: $prompt,
            volume: $volume,
            folder: $folder,
            filename: $filename,
            title: $title,
            alt: $alt,
        );

        if ($saved->isError) {
            return $saved;
        }

        /** @var array{images: list<array{id: int, url: ?string, filename: string, mimeType: string, width: int|null, height: int|null, revisedPrompt?: string}>} $payload */
        $payload = json_decode($saved->text, true, flags: JSON_THROW_ON_ERROR);
        $image = $payload['images'][0];
        $assetId = $image['id'];
        $filename = $image['filename'];
        $ratioLabel = $aspectRatio ?? 'default (1:1)';
        $notes = "Generated image saved as asset id={$assetId} ({$filename}) via Nano Banana at aspect ratio {$ratioLabel}. The asset is now in volume \"{$volume->handle}\" — attach it to an entry field via upsert_entry, or call get_asset with id={$assetId} to inspect its metadata.";

        return ['_notes' => $notes, 'data' => $payload];
    }
}
