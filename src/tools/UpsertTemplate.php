<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\helpers\FileHelper;
use markhuot\craftai\attributes\Description;
use Twig\Error\SyntaxError;
use Twig\Source;

/**
 * Create or overwrite a Twig template inside the site `templates/` directory.
 * Writes the given `contents` to the file at `path`, creating any missing
 * intermediate directories. If the file already exists, its contents are
 * replaced; otherwise a new file is created.
 *
 * `path` is always resolved relative to the site templates root and must end
 * in one of the configured `defaultTemplateExtensions` (default: `twig`,
 * `html`). Absolute paths and paths that escape the templates root via `..`
 * are rejected. Plugin-registered template roots are read-only — writes only
 * land inside the user's `templates/` directory.
 *
 * Twig syntax is validated before the file is written — if the contents
 * fail to tokenize/parse, the tool returns the syntax error (with line
 * number) and does not touch the filesystem. This prevents a broken write
 * from taking the site down with a 500.
 */
class UpsertTemplate extends Tool
{
    /**
     * @return array{_notes: string, data: array{path: string, absolutePath: string, size: int, created: bool}}|ToolOutput
     */
    public function __invoke(
        #[Description('Template path relative to the site templates directory (e.g. "blog/post.twig"). Must end in an allowed template extension. Cannot be absolute or contain ".." segments that escape the root.')]
        string $path,
        #[Description('Full template contents to write. Replaces the file if it already exists. Pass an empty string to write an empty file.')]
        string $contents = '',
    ): array|ToolOutput {
        $extensions = Craft::$app->getConfig()->getGeneral()->defaultTemplateExtensions;

        $relative = self::normalizeRelativePath($path);
        if ($relative === null) {
            return new ToolOutput(
                "Validation failed: path \"{$path}\" must be relative to the templates directory and cannot contain \"..\" segments.",
                isError: true,
            );
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if ($extension === '' || ! in_array($extension, $extensions, true)) {
            $allowed = implode(', ', $extensions);

            return new ToolOutput(
                "Validation failed: path \"{$path}\" must end in one of: {$allowed}.",
                isError: true,
            );
        }

        $templatesRoot = FileHelper::normalizePath(Craft::$app->getPath()->getSiteTemplatesPath());
        FileHelper::createDirectory($templatesRoot);
        $resolvedRoot = realpath($templatesRoot);
        if ($resolvedRoot === false) {
            return new ToolOutput(
                'Could not resolve the templates directory.',
                isError: true,
            );
        }

        $target = FileHelper::normalizePath($templatesRoot.DIRECTORY_SEPARATOR.$relative);
        $created = ! is_file($target);

        FileHelper::createDirectory(dirname($target));

        $resolvedParent = realpath(dirname($target));
        if ($resolvedParent === false
            || ! str_starts_with($resolvedParent.DIRECTORY_SEPARATOR, $resolvedRoot.DIRECTORY_SEPARATOR)
        ) {
            return new ToolOutput(
                "Validation failed: path \"{$path}\" resolves outside the templates directory.",
                isError: true,
            );
        }

        if (is_link($target)) {
            return new ToolOutput(
                "Validation failed: path \"{$path}\" points to a symbolic link and cannot be overwritten.",
                isError: true,
            );
        }

        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

        if (($syntaxError = self::twigSyntaxError($contents, $relativePath)) !== null) {
            return new ToolOutput(
                "Refusing to write \"{$relativePath}\": ".$syntaxError,
                isError: true,
            );
        }

        $resolvedTarget = $resolvedParent.DIRECTORY_SEPARATOR.basename($target);

        if (file_put_contents($resolvedTarget, $contents) === false) {
            return new ToolOutput(
                "Could not write template at \"{$path}\".",
                isError: true,
            );
        }

        $notes = sprintf(
            'Template "%s" %s (%d bytes). Use get_template path="%s" to re-read it, or reference it from a section via upsert_section template="%s".',
            $relativePath,
            $created ? 'created' : 'overwritten',
            strlen($contents),
            $relativePath,
            preg_replace('/\.[^.]+$/', '', $relativePath),
        );

        return [
            '_notes' => $notes,
            'data' => [
                'path' => $relativePath,
                'absolutePath' => $resolvedTarget,
                'size' => strlen($contents),
                'created' => $created,
            ],
        ];
    }

    /**
     * Parse the template through Twig's lexer + parser without executing it,
     * so we catch syntax errors (`indexOf`-style unknown filters, mismatched
     * tags, etc.) before clobbering a working file. Returns a short error
     * message including the source line, or null when the template parses.
     */
    private static function twigSyntaxError(string $contents, string $relativePath): ?string
    {
        try {
            $twig = Craft::$app->getView()->getTwig();
            $stream = $twig->tokenize(new Source($contents, $relativePath));
            $twig->parse($stream);
        } catch (SyntaxError $e) {
            $line = $e->getTemplateLine();

            return $line > 0
                ? "Twig syntax error on line {$line}: ".$e->getRawMessage()
                : 'Twig syntax error: '.$e->getRawMessage();
        }

        return null;
    }

    private static function normalizeRelativePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (str_contains($path, "\0")) {
            return null;
        }

        if (preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path) === 1) {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $segments[] = $segment;
        }

        if ($segments === []) {
            return null;
        }

        return implode(DIRECTORY_SEPARATOR, $segments);
    }
}
