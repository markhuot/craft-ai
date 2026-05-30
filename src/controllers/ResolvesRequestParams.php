<?php

namespace markhuot\craftai\controllers;

use yii\web\BadRequestHttpException;

/**
 * Typed accessors for request body / query params.
 *
 * Craft's `Request` only ever hands back a raw `mixed` value, so every
 * controller action otherwise repeats the same `is_numeric()` / `is_string()`
 * guards and casts before it can trust an input. These helpers centralize that
 * coercion + validation in one place:
 *
 *   - the `getRequired*` variants throw a 400 when the param is missing or the
 *     wrong shape (an int param that isn't numeric, a string param that is
 *     empty), so the action body can treat the return value as already-valid;
 *   - the nullable variants coerce a well-formed value or fall back to the
 *     supplied default (`null` unless overridden), letting optional params read
 *     as a single expression instead of a value + guard + cast dance.
 *
 * `$trim` and `$maxLength` are opt-in on the string helpers so the common
 * "non-empty, bounded, whitespace-collapsed" inputs (comment bodies, reference
 * ids) don't each re-implement the same post-validation.
 *
 * Used by controllers extending {@see \craft\web\Controller}, which exposes the
 * request component as `$this->request`.
 *
 * @property \craft\web\Request $request
 */
trait ResolvesRequestParams
{
    private function getRequiredIntBodyParam(string $name): int
    {
        return $this->requireIntParam($name, $this->request->getRequiredBodyParam($name));
    }

    private function getIntBodyParam(string $name, ?int $default = null): ?int
    {
        return $this->coerceIntParam($this->request->getBodyParam($name), $default);
    }

    private function getRequiredIntQueryParam(string $name): int
    {
        return $this->requireIntParam($name, $this->request->getRequiredQueryParam($name));
    }

    private function getIntQueryParam(string $name, ?int $default = null): ?int
    {
        return $this->coerceIntParam($this->request->getQueryParam($name), $default);
    }

    /**
     * Read from either the query string or the body (Craft's `getParam`
     * checks both). For endpoints reachable as GET or POST that don't care
     * which side a value rode in on.
     */
    private function getRequiredIntParam(string $name): int
    {
        return $this->requireIntParam($name, $this->request->getParam($name));
    }

    private function getIntParam(string $name, ?int $default = null): ?int
    {
        return $this->coerceIntParam($this->request->getParam($name), $default);
    }

    private function getStringParam(string $name, ?string $default = null, bool $trim = false, ?int $maxLength = null): ?string
    {
        return $this->coerceStringParam($name, $this->request->getParam($name), $default, $trim, $maxLength);
    }

    private function getBoolBodyParam(string $name, bool $default = false): bool
    {
        return (bool) $this->request->getBodyParam($name, $default);
    }

    private function getBoolQueryParam(string $name, bool $default = false): bool
    {
        return (bool) $this->request->getQueryParam($name, $default);
    }

    private function getRequiredStringBodyParam(string $name, bool $trim = false, ?int $maxLength = null): string
    {
        return $this->requireStringParam($name, $this->request->getRequiredBodyParam($name), $trim, $maxLength);
    }

    private function getStringBodyParam(string $name, ?string $default = null, bool $trim = false, ?int $maxLength = null): ?string
    {
        return $this->coerceStringParam($name, $this->request->getBodyParam($name), $default, $trim, $maxLength);
    }

    private function getRequiredStringQueryParam(string $name, bool $trim = false, ?int $maxLength = null): string
    {
        return $this->requireStringParam($name, $this->request->getRequiredQueryParam($name), $trim, $maxLength);
    }

    private function getStringQueryParam(string $name, ?string $default = null, bool $trim = false, ?int $maxLength = null): ?string
    {
        return $this->coerceStringParam($name, $this->request->getQueryParam($name), $default, $trim, $maxLength);
    }

    /**
     * Coerce a query param to one of a fixed set of allowed values.
     *
     * A missing param falls back to `$default`; a present-but-unrecognized
     * value is a 400. Callers get back a value guaranteed to be in
     * `$allowed`, so they don't need to re-check it themselves.
     *
     * @param non-empty-list<string> $allowed
     */
    private function getStringEnumQueryParam(string $name, array $allowed, string $default): string
    {
        $value = $this->request->getQueryParam($name);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        if (! in_array($value, $allowed, true)) {
            throw new BadRequestHttpException("{$name} must be one of: " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    private function requireIntParam(string $name, mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new BadRequestHttpException("{$name} must be numeric.");
        }

        return (int) $value;
    }

    private function coerceIntParam(mixed $value, ?int $default): ?int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function requireStringParam(string $name, mixed $value, bool $trim, ?int $maxLength): string
    {
        if (! is_string($value)) {
            throw new BadRequestHttpException("{$name} must be a non-empty string.");
        }
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '') {
            throw new BadRequestHttpException("{$name} must be a non-empty string.");
        }
        if ($maxLength !== null && strlen($value) > $maxLength) {
            throw new BadRequestHttpException("{$name} must be {$maxLength} characters or fewer.");
        }

        return $value;
    }

    private function coerceStringParam(string $name, mixed $value, ?string $default, bool $trim, ?int $maxLength): ?string
    {
        if (! is_string($value)) {
            return $default;
        }
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '') {
            return $default;
        }
        if ($maxLength !== null && strlen($value) > $maxLength) {
            throw new BadRequestHttpException("{$name} must be {$maxLength} characters or fewer.");
        }

        return $value;
    }
}
