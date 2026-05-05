<?php

namespace markhuot\craftai\web\assets\widget;

use craft\web\AssetBundle;

/**
 * Source-path holder for the front-end chat widget bundle.
 *
 * The bundle deliberately leaves $js and $css empty: the widget runs on the
 * site front-end and we can't rely on the host template calling
 * {% endbody %}, so the loader script is injected manually from the
 * View::EVENT_AFTER_RENDER_PAGE_TEMPLATE listener in Plugin::init(). This
 * class exists so consumers can resolve the published URL via the standard
 * asset-manager pathway, and so the source directory is documented in code.
 */
class WidgetAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__.'/dist';

        parent::init();
    }
}
