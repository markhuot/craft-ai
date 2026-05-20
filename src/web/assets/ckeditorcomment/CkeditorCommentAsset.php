<?php

namespace markhuot\craftai\web\assets\ckeditorcomment;

use craft\ckeditor\web\assets\BaseCkeditorPackageAsset;

/**
 * CKEditor 5 plugin bundle that adds a "Comment" toolbar button to
 * CKEditor fields. Selecting some text + clicking the button wraps the
 * selection in a `<span data-craft-ai-comment-id="…">` marker and posts
 * a fresh comment to the craft-ai overlay so the field-level review
 * indicators can pin themselves to a precise range of prose instead of
 * the whole field heading.
 *
 * Registered via `craft\ckeditor\Plugin::registerCkeditorPackage()` from
 * the plugin's init — only when the CKEditor plugin class is actually
 * loaded, so installations without craftcms/ckeditor stay clean.
 *
 * The toolbar button does **not** auto-appear in existing CKEditor
 * configs. To enable it on a specific field, an admin adds
 * `craftAiComment` to that field's CKEditor config toolbar (Settings →
 * CKEditor → Edit Config). This matches how craftcms/ckeditor expects
 * external packages to opt-in per config.
 */
class CkeditorCommentAsset extends BaseCkeditorPackageAsset
{
    /**
     * Namespace under which our plugin exports get exposed to CKEditor's
     * import map. `craft\ckeditor\Plugin::init()` calls
     * `view->registerJsImport($bundle->namespace, …)` so the generated
     * `import { CraftAiComment } from "@markhuot/craft-ai-comment";`
     * resolves to our bundle at runtime.
     *
     * The string itself is just an identifier — CKEditor never fetches
     * a real npm package by this name — but we follow the
     * `@scope/ckeditor5-<handle>` convention the README recommends so
     * it's recognizable in dev tools and won't collide with first-party
     * packages.
     */
    public string $namespace = '@markhuot/craft-ai-comment';

    /**
     * @var array<string>
     */
    public array $pluginNames = ['CraftAiComment'];

    /**
     * @var array<string|string[]>
     */
    public array $toolbarItems = ['craftAiComment'];

    public function init(): void
    {
        $this->sourcePath = __DIR__.'/dist';

        // `type: module` is load-bearing: CKEditor 5's loader maps our
        // `$namespace` to this same file via an import map, and the
        // browser needs the script tag to be a module so it shares a
        // single module cache entry between the import-map lookup and
        // any explicit `import { CraftAiComment } from "@…"` statement
        // CKEditor emits. Registering the module here also kicks off
        // the fetch early instead of waiting for the editor to ask.
        $this->js = [
            ['ckeditor-comment.js', 'type' => 'module'],
        ];

        $this->css = [
            'ckeditor-comment.css',
        ];

        parent::init();
    }
}
