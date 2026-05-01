<?php

namespace markhuot\craftai\binders;

use Craft;

class Site implements Binder
{
    public function bind(mixed $value, array $arguments): ?\craft\models\Site
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return Craft::$app->sites->getSiteById((int) $value);
        }

        if (! is_string($value)) {
            return null;
        }

        return Craft::$app->sites->getSiteByHandle($value);
    }
}
