<?php

namespace markhuot\craftai\features;

use craft\elements\Asset;
use markhuot\craftai\models\EditImageResponse;

interface EditImage
{
    function editImage(string $prompt, Asset $asset, string $mask, int $count=1): EditImageResponse;
}
