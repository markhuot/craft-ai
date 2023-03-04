<?php

namespace markhuot\craftai\features;

use craft\elements\Asset;
use markhuot\craftai\models\ImageCaptionResponse;

interface Caption
{
    public function generateCaption(Asset $asset): ImageCaptionResponse;
}
