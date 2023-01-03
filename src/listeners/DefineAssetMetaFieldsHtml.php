<?php

namespace markhuot\craftai\listeners;

use craft\events\DefineHtmlEvent;
use craft\events\DefineMetadataEvent;

class DefineAssetMetaFieldsHtml
{
    function handle(DefineHtmlEvent $event)
    {
        $event->html .= '<p>Description: foo [Regenerate]</p>';
    }
}