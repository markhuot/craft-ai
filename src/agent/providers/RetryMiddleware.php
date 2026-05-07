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
 * (opencode.ai, OpenRouter, vendor incidents, etc.) or a connection blip
 * doesn't bubble up as a hard failure into AgentLoop — the loop already
 * persists provider failures as `error` blocks, which derails the
 * conversation, so it's much better to swallow the temporary glitch here
 * and re-issue the same request a few times before giving up.
 *
 * Retry-able conditions:
 *   - 5xx response (the upstream said "I broke" — almost always transient)
 *   - {@see ConnectException} (DNS / TCP / TLS / read-timeout)
 *
 * NOT retried:
 *   - 4xx — we sent something the upstream didn't like; retrying just gets
 *     the same rejection. {@see AgentLoop} should learn from the error.
 *   - 429 — handled as fatal for now. A future revision could honor a
 *     `Retry-After` header here, but that needs careful coordination with
 *     queue-job timeout windows so we don't strand a worker for minutes.
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

            return $status !== null && $status >= 500 && $status < 600;
        };
    }

    /**
     * Exponential backoff with jitter: 1s, 2s, 4s, 8s plus 0–500ms jitter
     * to avoid thundering-herd retries when many sessions hit the same
     * outage at the same moment.
     *
     * @return callable(int $retries): int  Delay in milliseconds.
     */
    public static function exponentialBackoff(): callable
    {
        return static fn (int $retries): int => (1 << $retries) * 1000 + random_int(0, 500);
    }
}
