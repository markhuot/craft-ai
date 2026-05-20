<?php

namespace markhuot\craftai\agent\providers;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Retry policy for upstream LLM and image-generation HTTP calls. Wraps every
 * provider's default Guzzle client so a transient 5xx from a gateway
 * (opencode.ai, OpenRouter, vendor incidents, etc.), a 429 rate-limit, or a
 * connection blip doesn't bubble up as a hard failure into AgentLoop — the
 * loop already persists provider failures as `error` blocks, which derails
 * the conversation, so it's much better to swallow the temporary glitch
 * here and re-issue the same request a few times before giving up.
 *
 * Retry-able conditions:
 *   - 5xx response (the upstream said "I broke" — almost always transient)
 *   - 429 response (rate limited — back off and try again; Retry-After is
 *     honored when present so we wait the server-suggested interval)
 *   - {@see ConnectException} (DNS / TCP / TLS / read-timeout)
 *
 * NOT retried:
 *   - Other 4xx — we sent something the upstream didn't like; retrying
 *     just gets the same rejection. {@see AgentLoop} should learn from
 *     the error.
 *
 * Detection note: 429 is a real HTTP status code on the response, so we
 * read `$response->getStatusCode()` (or unwrap it from
 * {@see BadResponseException}) rather than string-matching the response
 * body — that's robust to any wording the upstream chooses.
 */
class RetryMiddleware
{
    /** Total attempts = 1 + MAX_RETRIES. With 4 retries that's up to 5 calls. */
    public const MAX_RETRIES = 4;

    /**
     * Push the retry middleware onto a handler stack. The optional `$delay`
     * override is exposed for tests so they can use zero delay; production
     * callers should leave it null and get the exponential default.
     *
     * @param  ?callable(int $retries): int  $delay  Override delay-in-ms
     *         function. Defaults to {@see exponentialBackoff}.
     */
    public static function attach(HandlerStack $stack, ?callable $delay = null): void
    {
        $stack->push(Middleware::retry(
            self::decider(self::MAX_RETRIES),
            $delay ?? self::exponentialBackoff(),
        ));
    }

    /**
     * @return callable(int, RequestInterface, ?ResponseInterface, ?\Throwable): bool
     */
    public static function decider(int $maxRetries = self::MAX_RETRIES): callable
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null,
        ) use ($maxRetries): bool {
            if ($retries >= $maxRetries) {
                return false;
            }

            // Network-layer failure — DNS, refused connection, TLS, read timeout.
            // These have no response body to inspect; just retry.
            if ($exception instanceof ConnectException) {
                return true;
            }

            // The upstream returned a 5xx. http_errors (the default Guzzle
            // middleware) wraps that into a BadResponseException whose
            // getResponse() carries the original response. We pull the status
            // from whichever source has it.
            $status = null;
            if ($response !== null) {
                $status = $response->getStatusCode();
            } elseif ($exception instanceof BadResponseException) {
                $status = $exception->getResponse()->getStatusCode();
            }

            if ($status === null) {
                return false;
            }

            // 5xx — upstream is broken. Almost always transient.
            if ($status >= 500 && $status < 600) {
                return true;
            }

            // 429 — rate limited. Back off (honoring Retry-After in the
            // delay function) and try again.
            return $status === 429;
        };
    }

    /**
     * Exponential backoff with jitter: 1s, 2s, 4s, 8s plus 0–500ms jitter
     * to avoid thundering-herd retries when many sessions hit the same
     * outage at the same moment.
     *
     * When the upstream sent a `Retry-After` header (typically alongside a
     * 429 or a 503), honor it so we wait the server-suggested interval
     * instead of guessing. Capped at 60s so a misconfigured or hostile
     * server can't pin a queue worker indefinitely — TTR is 24h so this
     * cap is purely about responsiveness, not job survival.
     *
     * @return callable(int $retries, ?ResponseInterface $response=): int  Delay in milliseconds.
     */
    public static function exponentialBackoff(): callable
    {
        return static function (int $retries, ?ResponseInterface $response = null): int {
            if ($response !== null) {
                $retryAfterMs = self::retryAfterMs($response);
                if ($retryAfterMs !== null) {
                    return min($retryAfterMs, 60_000);
                }
            }

            return (1 << $retries) * 1000 + random_int(0, 500);
        };
    }

    /**
     * Parse the `Retry-After` header. Returns null when the header is
     * absent or unparseable.
     *
     * Two RFC 7231 forms are supported:
     *   - Delta seconds (e.g. `Retry-After: 5`) — integer.
     *   - HTTP-date (e.g. `Retry-After: Wed, 21 Oct 2015 07:28:00 GMT`).
     *
     * The result is clamped to >= 0 so a past HTTP-date (clock skew, or
     * the value already elapsed) becomes "retry immediately" rather than
     * a negative delay.
     */
    private static function retryAfterMs(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));
        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header * 1000;
        }

        $when = strtotime($header);
        if ($when === false) {
            return null;
        }

        $ms = ($when - time()) * 1000;

        return $ms > 0 ? $ms : 0;
    }
}
