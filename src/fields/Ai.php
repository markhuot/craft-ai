<?php

namespace markhuot\craftai\fields;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;

class Ai extends Field implements FieldInterface
{
    protected function inputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        return \Craft::$app->view->renderTemplate('ai/_cp/fields/ai');
    }
}
