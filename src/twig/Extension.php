<?php

namespace markhuot\craftai\twig;

use function markhuot\openai\helpers\web\session;
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

    public function old(string $key, object|string $default = null): ?string
    {
        /** @var array<array-key, string> $flashes */
        $flashes = session()->getAllFlashes();

        if ($flashes['old.'.$key] ?? false) {
            return $flashes['old.'.$key];
        }

        if (is_object($default)) {
            $properties = explode('.', $key);
            foreach ($properties as $prop) {
                if (is_object($default)) {
                    $default = $default->{$prop} ?? null;
                } elseif (is_array($default)) {
                    $default = $default[$prop] ?? null;
                } else {
                    throw new \RuntimeException('Could not find default value.');
                }
            }

            return $default;
        }

        return $default;
    }

    public function flash(string $key): ?string
    {
        /** @var ?string $flash */
        $flash = session()->getFlash($key);

        return $flash;
    }
}
