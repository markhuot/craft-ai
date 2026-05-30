<?php

namespace markhuot\craftai\listeners;

use craft\ckeditor\web\assets\ckeditor\CkeditorAsset;
use craft\events\AssetBundleEvent;
use craft\web\View;
use markhuot\craftai\web\assets\ckeditorcomment\CkeditorCommentAsset;

/**
 * Register the import-MAP entry that makes
 * `import { CraftAiComment } from "@markhuot/craft-ai-comment"` resolve to
 * a real URL in the browser. craft\ckeditor registers package imports
 * inside its own init() — *once*, by iterating `$ckeditorImports` at boot.
 * Plugin handles sort alphabetically and `ckeditor` runs before `craft-ai`,
 * so by the time our init calls `registerCkeditorPackage`, ckeditor's loop
 * has already consumed an empty array and the browser ends up with no
 * mapping for our namespace ("Module name … does not resolve to a valid
 * URL"). Wiring the import ourselves on the same event ckeditor itself
 * uses for the rest of the package registration makes the order stop
 * mattering.
 */
class RegisterCkeditorCommentImport
{
    public function __invoke(AssetBundleEvent $event): void
    {
        if (! $event->bundle instanceof CkeditorAsset) {
            return;
        }
        /** @var View $view */
        $view = $event->sender;
        $assetManager = $view->getAssetManager();

        // `getBundle` instantiates without registering, which is
        // what we want here — we only need the published URL
        // for the import map. The bundle's own `registerPackage`
        // (and CSS / module-script tag) come from ckeditor's
        // listener that fires alongside this one.
        $bundle = $assetManager->getBundle(CkeditorCommentAsset::class);
        if (! $bundle instanceof CkeditorCommentAsset) {
            return;
        }

        $view->registerJsImport(
            $bundle->namespace,
            $assetManager->getAssetUrl($bundle, 'ckeditor-comment.js', false),
        );
    }
}
