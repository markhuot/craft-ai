<?php

namespace markhuot\craftai\binders;

use Craft;

class Volume implements Binder
{
    public function bind(mixed $value, array $arguments): ?\craft\models\Volume
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Craft::$app->volumes->getVolumeById((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        return Craft::$app->volumes->getVolumeByHandle($value);
    }
}
