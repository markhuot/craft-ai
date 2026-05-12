<?php

namespace markhuot\craftai\tools;

use craft\elements\Asset;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\validators\ExistingSite;
use markhuot\craftai\validators\ExistingVolume;

/**
 * Search for assets in the CMS. Returns a list of assets matching the given
 * filters. All parameters are optional and can be combined.
 *
 * Each result includes the asset's ID, filename, kind, size, dimensions, and
 * custom field values. Results default to 25 — use limit and offset to
 * paginate. For a single asset's full details (including a public URL), call
 * get_asset with the id.
 */
class GetAssets extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{_notes: string, data: list<array<array-key, mixed>>}
     */
    public function __invoke(
        #[Description('Full-text search query (e.g. "logo", "hero image")')]
        ?string $search = null,
        #[Description('Volume handle to filter by (e.g. "uploads")')]
        #[Validate(ExistingVolume::class)]
        ?string $volume = null,
        #[Description('File kind: "image", "video", "audio", "pdf", "compressed", "excel", "word", "powerpoint", "text", "json", "xml", "html", "javascript", "photoshop", "illustrator", "flash", "access", "captionsSubtitles", "php", "unknown". Supports negation ("not image") or array values.')]
        #[Validate('in', range: ['access', 'audio', 'captionsSubtitles', 'compressed', 'excel', 'flash', 'html', 'illustrator', 'image', 'javascript', 'json', 'pdf', 'photoshop', 'php', 'powerpoint', 'text', 'video', 'word', 'xml', 'unknown'])]
        ?string $kind = null,
        #[Description('Status filter: "enabled" (default), "disabled", or "any" for all')]
        #[Validate('in', range: ['enabled', 'disabled', 'any'])]
        ?string $status = null,
        #[Description('Filter by exact title')]
        ?string $title = null,
        #[Description('Filter by filename. Supports wildcards (e.g. "*.jpg", "logo*")')]
        ?string $filename = null,
        #[Description('Filter by folder path within the volume (e.g. "subfolder/", "subfolder/*" to include nested)')]
        ?string $folderPath = null,
        #[Description('Filter by exact folder ID (use with includeSubfolders to include nested folders)')]
        ?int $folderId = null,
        #[Description('When folderId is set, also include assets in subfolders')]
        ?bool $includeSubfolders = null,
        #[Description('Filter to assets uploaded by this user ID')]
        ?int $uploaderId = null,
        #[Description('Filter by whether the asset has alt text set (true) or not (false)')]
        ?bool $hasAlt = null,
        #[Description('Filter by image width in pixels. Supports comparison operators (e.g. ">= 1000")')]
        ?string $width = null,
        #[Description('Filter by image height in pixels. Supports comparison operators (e.g. "<= 500")')]
        ?string $height = null,
        #[Description('Filter by file size in bytes. Supports comparison operators (e.g. "< 1000000")')]
        ?string $size = null,
        #[Description('Site handle for multi-site installs (e.g. "english", "french")')]
        #[Validate(ExistingSite::class)]
        ?string $site = null,
        #[Description('Return only assets created before this date (e.g. "2024-01-01", "today", "3 months ago")')]
        ?string $before = null,
        #[Description('Return only assets created on or after this date (e.g. "2024-01-01", "yesterday")')]
        ?string $after = null,
        #[Description('Return only assets whose underlying file was modified relative to this value (e.g. ">= 2024-01-01")')]
        ?string $dateModified = null,
        #[Description('Sort order (e.g. "filename ASC", "dateCreated DESC", "size DESC")')]
        ?string $orderBy = null,
        #[Description('Maximum number of assets to return (default 25)')]
        ?int $limit = 25,
        #[Description('Number of assets to skip for pagination')]
        ?int $offset = null,
    ): array {
        $query = Asset::find();

        if ($search !== null) {
            $query->search($search);
        }

        if ($volume !== null) {
            $query->volume($volume);
        }

        if ($kind !== null) {
            $query->kind($kind);
        }

        if ($status !== null) {
            if ($status === 'any') {
                $query->status(null);
            } else {
                $query->status($status);
            }
        }

        if ($title !== null) {
            $query->title($title);
        }

        if ($filename !== null) {
            $query->filename($filename);
        }

        if ($folderPath !== null) {
            $query->folderPath($folderPath);
        }

        if ($folderId !== null) {
            $query->folderId($folderId);
        }

        if ($includeSubfolders !== null) {
            $query->includeSubfolders($includeSubfolders);
        }

        if ($uploaderId !== null) {
            $query->uploader($uploaderId);
        }

        if ($hasAlt !== null) {
            $query->hasAlt($hasAlt);
        }

        if ($width !== null) {
            $query->width($width);
        }

        if ($height !== null) {
            $query->height($height);
        }

        if ($size !== null) {
            $query->size($size);
        }

        if ($site !== null) {
            $query->site($site);
        }

        if ($before !== null) {
            $query->before($before);
        }

        if ($after !== null) {
            $query->after($after);
        }

        if ($dateModified !== null) {
            $query->dateModified($dateModified);
        }

        if ($orderBy !== null) {
            $query->orderBy($orderBy);
        }

        $query->limit($limit);

        if ($offset !== null) {
            $query->offset($offset);
        }

        $data = array_values(array_map(
            static function (Asset $asset): array {
                $row = $asset->toArray();
                $row['mimeType'] = $asset->getMimeType();
                $row['url'] = $asset->getUrl();

                return $row;
            },
            $query->all(),
        ));

        $appliedLimit = $limit ?? 25;
        $hitLimit = count($data) === $appliedLimit;
        $notes = $data === []
            ? 'No assets matched the given filters. Loosen filters (e.g. status: "any") or call get_volumes to see what volumes exist.'
            : 'Returned '.count($data).' asset(s)'
                .($hitLimit ? " (limit={$appliedLimit} reached; pass offset to paginate)" : '')
                .'. Use get_asset with an id for full details, or upsert_asset with an id to modify.';

        return [
            '_notes' => $notes,
            'data' => $data,
        ];
    }
}
