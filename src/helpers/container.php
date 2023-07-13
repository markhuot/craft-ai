<?php

namespace markhuot\craftai\helpers;

/**
 * @template T
 *
 * @param  class-string<T>  $className
 * @return T
 */
function app(string $className)
{
    return \Craft::$container->get($className); // @phpstan-ignore-line We know it's of type T. The Yii docblocks are just bad.
}
