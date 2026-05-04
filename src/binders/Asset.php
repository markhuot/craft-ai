<?php

namespace markhuot\craftai\binders;

use craft\elements\Asset as AssetElement;

class Asset implements Binder
{
    public function bind(mixed $value, array $arguments): ?AssetElement
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $asset = AssetElement::find()->id((int) $value)->status(null)->one();

        return $asset instanceof AssetElement ? $asset : null;
    }
}
