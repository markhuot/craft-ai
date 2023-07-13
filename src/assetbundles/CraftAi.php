<?php

namespace markhuot\craftai\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class CraftAi extends AssetBundle
{
    public function init(): void
    {
        // define the path that your publishable resources live
        $this->sourcePath = '@ai/resources';

        // define the dependencies
        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
        ];

        $this->css = [
            'styles.css',
        ];

        parent::init();
    }
}
