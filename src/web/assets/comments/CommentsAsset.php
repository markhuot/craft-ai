<?php

namespace markhuot\craftai\web\assets\comments;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * Asset bundle for the CP comments overlay. Loaded on every CP page request
 * by the plugin's after-render listener; the bundle itself short-circuits
 * when the page lacks an `elementId` hidden input (i.e. it's not an entry
 * edit screen), so registering globally is cheaper than trying to detect
 * "is this an entry edit URL" server-side and getting it wrong on third-
 * party plugin routes.
 */
class CommentsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__.'/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'comments.css',
        ];

        $this->js = [
            ['comments.js', 'type' => 'module'],
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        parent::init();
    }
}
