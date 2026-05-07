<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\web\View;
use markhuot\craftai\attributes\Description;
use markhuot\craftai\attributes\Validate;
use markhuot\craftai\validators\ExistingTemplate;

/**
 * Read the contents of a single site (front-end) Twig template. Accepts the
 * `path` returned by `get_templates` (e.g. `blog/post.twig`) or the bare
 * template name (e.g. `blog/post`) — both resolve via Craft's normal
 * template-lookup rules.
 *
 * Returns the resolved absolute path alongside the file contents, so callers
 * can confirm exactly which file was loaded when multiple template roots
 * could match.
 */
class GetTemplate extends Tool
{
    public const KIND = ToolKind::Read;

    /**
     * @return array{path: string, absolutePath: string, contents: string}
     */
    public function __invoke(
        #[Description('Template path or name (e.g. "blog/post.twig" or "blog/post"). Resolved against the site templates directory and any plugin-registered template roots.')]
        #[Validate(ExistingTemplate::class)]
        string $path,
    ): array {
        $resolved = Craft::$app->getView()->resolveTemplate($path, View::TEMPLATE_MODE_SITE);

        if ($resolved === false) {
            throw new \RuntimeException("No template found at \"{$path}\".");
        }

        $contents = file_get_contents($resolved);

        if ($contents === false) {
            throw new \RuntimeException("Failed to read template at \"{$resolved}\".");
        }

        return [
            'path' => $path,
            'absolutePath' => $resolved,
            'contents' => $contents,
        ];
    }
}
