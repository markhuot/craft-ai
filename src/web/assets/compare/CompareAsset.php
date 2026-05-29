<?php

namespace markhuot\craftai\web\assets\compare;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Asset bundle for the full-page "compare revisions" view. Registered by
 * craft-ai/compare/view.twig, which mounts the `compare` React bundle.
 */
class CompareAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__.'/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'compare.css',
        ];

        $this->js = [
            ['compare.js', 'type' => 'module'],
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        parent::init();
    }
}
