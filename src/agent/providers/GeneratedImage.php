<?php

namespace markhuot\craftai\agent\providers;

/**
 * One image returned from an ImageProvider. Bytes are the raw decoded image
 * payload, suitable for writing directly to disk; the provider is responsible
 * for any base64 decoding before constructing this.
 */
class GeneratedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mimeType,
        public readonly ?string $revisedPrompt = null,
    ) {}

    public function extension(): string
    {
        return match ($this->mimeType) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
