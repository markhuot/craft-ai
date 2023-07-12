<?php

namespace markhuot\craftai\fields;

use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use function markhuot\openai\helpers\web\view;

class Ai extends Field implements FieldInterface
{
    protected function inputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        return view()->renderTemplate('ai/_cp/fields/ai');
    }
}
