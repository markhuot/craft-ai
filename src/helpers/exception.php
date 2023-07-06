<?php

namespace markhuot\openai\helpers;

/**
 * @phpstan-assert-if-true true $condition
 */
function throw_if(bool $condition, mixed $message): void
{
    if (! $condition) {
        return;
    }

    if (is_string($message)) {
        throw new \RuntimeException($message);
    }

    if (is_callable($message)) {
        throw new \RuntimeException($message());
    }

    if (is_a(\Throwable::class, $message)) {
        throw $message;
    }
}
