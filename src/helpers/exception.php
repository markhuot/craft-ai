<?php

namespace markhuot\openai\helpers;

/**
 * @phpstan-assert !true $condition
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

    if (is_object($message) && is_a($message, \Throwable::class)) {
        throw $message;
    }

    throw new \RuntimeException('Invalid exception message type.');
}
