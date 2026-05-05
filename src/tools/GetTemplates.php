<?php

namespace markhuot\craftai\tools;

use Craft;
use craft\helpers\FileHelper;
use markhuot\craftai\attributes\Description;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * List Twig templates in the site's `templates/` directory. Returns each
 * template's path relative to the templates root, along with its absolute
 * file path and size in bytes. Use `get_template` to read a template's
 * contents.
 *
 * Only files matching the configured `defaultTemplateExtensions` (default:
 * `twig`, `html`) are returned. Hidden files and `node_modules/` are
 * skipped. Templates registered by plugins via `registerTemplateRoots` are
 * also included, prefixed with the root they were registered under.
 */
class GetTemplates extends Tool
{
    /**
     * @return list<array{path: string, absolutePath: string, size: int}>
     */
    public function __invoke(
        #[Description('Filter by template path prefix (e.g. "blog/" to only return templates under blog/). Matches against the path returned in each result.')]
        ?string $prefix = null,
    ): array {
        $extensions = Craft::$app->getConfig()->getGeneral()->defaultTemplateExtensions;

        /** @var array<string, list<string>> $roots */
        $roots = ['' => [Craft::$app->getPath()->getSiteTemplatesPath()]];
        foreach (Craft::$app->getView()->getSiteTemplateRoots() as $rootPath => $basePaths) {
            $roots[(string) $rootPath] = array_values(array_filter(
                is_array($basePaths) ? $basePaths : [$basePaths],
                static fn ($p): bool => is_string($p),
            ));
        }

        $results = [];
        $seen = [];

        foreach ($roots as $rootPath => $basePaths) {
            foreach ($basePaths as $basePath) {
                if (! is_dir($basePath)) {
                    continue;
                }

                $real = realpath($basePath);
                $resolvedBase = FileHelper::normalizePath($real !== false ? $real : $basePath);

                foreach (self::iterate($resolvedBase) as $file) {
                    if ($file->isDir()) {
                        continue;
                    }

                    $extension = strtolower($file->getExtension());
                    if (! in_array($extension, $extensions, true)) {
                        continue;
                    }

                    $absolutePath = FileHelper::normalizePath($file->getRealPath() ?: $file->getPathname());
                    $relative = ltrim(substr($absolutePath, strlen($resolvedBase)), DIRECTORY_SEPARATOR);
                    $path = $rootPath === '' ? $relative : $rootPath.'/'.$relative;
                    $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);

                    if (isset($seen[$path])) {
                        continue;
                    }
                    $seen[$path] = true;

                    if ($prefix !== null && ! str_starts_with($path, $prefix)) {
                        continue;
                    }

                    $results[] = [
                        'path' => $path,
                        'absolutePath' => $absolutePath,
                        'size' => $file->getSize(),
                    ];
                }
            }
        }

        usort($results, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $results;
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private static function iterate(string $basePath): iterable
    {
        $directory = new RecursiveDirectoryIterator(
            $basePath,
            RecursiveDirectoryIterator::SKIP_DOTS,
        );

        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            static function (SplFileInfo $current): bool {
                $name = $current->getFilename();
                if ($name === '' || $name[0] === '.') {
                    return false;
                }
                if ($current->isDir() && $name === 'node_modules') {
                    return false;
                }

                return true;
            },
        );

        return new RecursiveIteratorIterator($filter);
    }
}
