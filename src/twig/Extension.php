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
            new TwigFunction('flash', [$this, 'flash']),
        ];
    }

    function old(string $key, object|string|null $default=null): ?string
    {
        /** @var array<array-key, string> $flashes */
        $flashes = \Craft::$app->session->getAllFlashes();

        if (Arr::exists($flashes, 'old.'.$key)) {
            return Arr::get($flashes, 'old.'.$key);
        }

        if (is_object($default)) {
            $properties = explode('.', $key);
            foreach ($properties as $prop) {
                if (is_object($default)) {
                    $default = $default->{$prop} ?? null;
                }
                else if (is_array($default)) {
                    $default = $default[$prop] ?? null;
                }
                else {
                    throw new \RuntimeException('Could not find default value.');
                }
            }
            return $default;
        }

        return $default;
    }

    function flash($key)
    {
        return \Craft::$app->session->getFlash($key);
    }
}
