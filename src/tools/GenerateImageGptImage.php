<?php

namespace markhuot\craftai\tools;

use craft\models\Volume;
use Craft;
use markhuot\craftai\agent\providers\ImageGenerationException;
use markhuot\craftai\agent\providers\OpenAiImageProvider;
use markhuot\craftai\attributes\Bind;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Tool as ToolAttribute;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\binders\Volume as VolumeBinder;
use markhuot\craftai\helpers\ImageAssetWriter;
use markhuot\craftai\validators\ExistingVolume;

/**
 * Generate an image with OpenAI's gpt-image-1 model and save it to a Craft
 * asset volume. Exposes gpt-image-1's native parameter surface — `size`,
 * `quality`, `background`, `output_format`, etc. — so the agent can use the
 * model exactly the way OpenAI's docs describe it, rather than going through
 * a lossy normalized abstraction.
 *
 * The model_name parameter selects between gpt-image-1 (default, current
 * flagship) and dall-e-3. Note that dall-e-3 has its own size/quality
 * vocabularies — pass dall-e-3 sizes (1024x1024, 1024x1792, 1792x1024) and
 * quality (standard/hd) when using that model.
 */
#[ToolAttribute(name: 'generate_image_gpt_image')]
class GenerateImageGptImage extends Tool
{
    public const KIND = ToolKind::LiveWrite;

    public function __construct(
        private readonly OpenAiImageProvider $provider,
    ) {}

    /**
     * @return array{_notes: string, data: array{images: list<array<string, mixed>>}}|ToolOutput
     */
    public function __invoke(
        #[Description('Text prompt describing the image to generate. Be specific about subject, style, composition, and lighting.')]
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
        #[Description('OpenAI model: "gpt-image-1" (default, current flagship) or "dall-e-3". Each has its own size/quality vocabularies; check below.')]
        #[Validate('in', range: ['gpt-image-1', 'dall-e-3'])]
        ?string $model = null,
        #[Description('Image dimensions. gpt-image-1 supports 1024x1024 (square), 1024x1536 (portrait), 1536x1024 (landscape), or auto. dall-e-3 supports 1024x1024, 1024x1792, 1792x1024.')]
        ?string $size = null,
        #[Description('Image quality. gpt-image-1: low/medium/high/auto. dall-e-3: standard/hd. Higher quality costs more.')]
        ?string $quality = null,
        #[Description('Background (gpt-image-1 only): "transparent" requires output_format=png or webp; "opaque" forces a solid background; "auto" lets the model decide.')]
        #[Validate('in', range: ['transparent', 'opaque', 'auto'])]
        ?string $background = null,
        #[Description('Output format (gpt-image-1 only): "png", "jpeg", or "webp". Use "png" for transparency.')]
        #[Validate('in', range: ['png', 'jpeg', 'webp'])]
        ?string $outputFormat = null,
        #[Description('Compression level 0-100 (gpt-image-1 only, jpeg/webp output). Higher means larger files but better quality.')]
        #[Validate('integer', min: 0, max: 100)]
        ?int $outputCompression = null,
        #[Description('Moderation strictness (gpt-image-1 only): "low" allows more permissive content, "auto" is the default.')]
        #[Validate('in', range: ['low', 'auto'])]
        ?string $moderation = null,
        #[Description('Style (dall-e-3 only): "vivid" produces hyper-real and dramatic images, "natural" produces more natural, less hyper-real images.')]
        #[Validate('in', range: ['vivid', 'natural'])]
        ?string $style = null,
    ): array|ToolOutput {
        assert($volume instanceof Volume);

        $resolvedModel = $model ?? 'gpt-image-1';

        /** @var array<string, mixed> $body */
        $body = [
            'model' => $resolvedModel,
            'prompt' => $prompt,
            'n' => 1,
        ];

        if ($size !== null) {
            $body['size'] = $size;
        }
        if ($quality !== null) {
            $body['quality'] = $quality;
        }

        if ($resolvedModel === 'gpt-image-1') {
            if ($background !== null) {
                $body['background'] = $background;
            }
            if ($outputFormat !== null) {
                $body['output_format'] = $outputFormat;
            }
            if ($outputCompression !== null) {
                $body['output_compression'] = $outputCompression;
            }
            if ($moderation !== null) {
                $body['moderation'] = $moderation;
            }
        }

        if ($resolvedModel === 'dall-e-3') {
            // dall-e-3 returns hosted URLs by default; force base64 so we can
            // save the bytes directly. gpt-image-1 always returns b64_json
            // and rejects this parameter, so we only set it for dall-e-3.
            $body['response_format'] = 'b64_json';
            if ($style !== null) {
                $body['style'] = $style;
            }
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
        $modelLabel = $resolvedModel;
        $notes = "Generated image saved as asset id={$assetId} ({$filename}) using {$modelLabel}. The asset is now in volume \"{$volume->handle}\" — attach it to an entry field via upsert_entry, or call get_asset with id={$assetId} to fetch its metadata.";

        return ['_notes' => $notes, 'data' => $payload];
    }
}
