<?php

namespace markhuot\craftai\twig;

use yii\base\Model;
use Illuminate\Support\Arr;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class Extension extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('old', [$this, 'old']),
        ];
    }

    function old($key, $default=null)
    {
        $flashes = \Craft::$app->session->getAllFlashes();
        if (Arr::exists($flashes, 'old.'.$key)) {
            return Arr::get($flashes, 'old.'.$key);
        }

        return $default;
    }
}
