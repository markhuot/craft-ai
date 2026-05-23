<?php

namespace markhuot\craftai\web\assets\aistar;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Asset bundle for the per-field "AI fill" star overlay. Loaded on every CP
 * page response by the plugin's after-render listener; the bundle itself
 * short-circuits when the page lacks an `elementId` / `draftId` hidden input,
 * so registering globally is cheaper than trying to detect "is this an entry
 * edit URL" server-side and getting it wrong on third-party plugin routes.
 */
class AiStarAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__.'/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'aistar.css',
        ];

        $this->js = [
            ['aistar.js', 'type' => 'module'],
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        parent::init();
    }
}
