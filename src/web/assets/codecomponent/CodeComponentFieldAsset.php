<?php

namespace markhuot\craftai\web\assets\codecomponent;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

class CodeComponentFieldAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__.'/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'code-component-field.css',
        ];

        $this->js = [
            ['code-component-field.js', 'type' => 'module'],
        ];

        $this->jsOptions = [
            'position' => View::POS_END,
        ];

        parent::init();
    }
}
