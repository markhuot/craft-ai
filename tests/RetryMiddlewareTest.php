<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use markhuot\craftai\agent\providers\RetryMiddleware;

it('retries a 5xx response and returns the eventual 200', function () {
    $mock = new MockHandler([
        new Response(500, [], 'oops'),
        new Response(503, [], 'still oops'),
        new Response(200, [], 'ok'),
    ]);
    $stack = HandlerStack::create($mock);
    RetryMiddleware::attach($stack, fn () => 0); // zero delay in tests
    $client = new Client(['handler' => $stack]);

    $response = $client->request('POST', 'https://example.test/');

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toBe('ok');
    expect($mock->count())->toBe(0); // queue exhausted
});

it('gives up after MAX_RETRIES + 1 attempts and propagates the final error', function () {
    // 5 total responses = 1 initial attempt + 4 retries. All fail.
    $responses = array_fill(0, RetryMiddleware::MAX_RETRIES + 1, new Response(500, [], 'down'));
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    RetryMiddleware::attach($stack, fn () => 0);
    $client = new Client(['handler' => $stack]);

    try {
        $client->request('POST', 'https://example.test/');
        expect(false)->toBeTrue('should have thrown');
    } catch (ServerException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(500);
        expect($mock->count())->toBe(0);
    }
});

it('does not retry a 4xx response — that\'s our problem to fix', function () {
    $mock = new MockHandler([
        new Response(400, [], 'bad request'),
        // If retry incorrectly fires, it would consume this — assertion
        // below verifies the queue still has it.
        new Response(200, [], 'should not be reached'),
    ]);
    $stack = HandlerStack::create($mock);
    RetryMiddleware::attach($stack, fn () => 0);
    $client = new Client(['handler' => $stack]);

    try {
        $client->request('POST', 'https://example.test/');
        expect(false)->toBeTrue('should have thrown');
    } catch (BadResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(400);
    }
    expect($mock->count())->toBe(1); // 200 is still queued
});

it('retries connection errors then succeeds', function () {
    $request = new Request('POST', 'https://example.test/');
    $mock = new MockHandler([
        new ConnectException('connection refused', $request),
        new ConnectException('still refused', $request),
        new Response(200, [], 'recovered'),
    ]);
    $stack = HandlerStack::create($mock);
    RetryMiddleware::attach($stack, fn () => 0);
    $client = new Client(['handler' => $stack]);

    $response = $client->request('POST', 'https://example.test/');

    expect($response->getStatusCode())->toBe(200);
    expect((string) $response->getBody())->toBe('recovered');
});

it('decider returns false once the retry budget is exhausted', function () {
    $decider = RetryMiddleware::decider(maxRetries: 3);
    $request = new Request('POST', 'https://example.test/');
    $response = new Response(500);

    expect($decider(0, $request, $response, null))->toBeTrue();
    expect($decider(2, $request, $response, null))->toBeTrue();
    expect($decider(3, $request, $response, null))->toBeFalse();
    expect($decider(99, $request, $response, null))->toBeFalse();
});

it('decider distinguishes 5xx (retry) from 4xx (no retry) from null status (no retry)', function () {
    $decider = RetryMiddleware::decider();
    $request = new Request('POST', 'https://example.test/');

    expect($decider(0, $request, new Response(500), null))->toBeTrue();
    expect($decider(0, $request, new Response(503), null))->toBeTrue();
    expect($decider(0, $request, new Response(599), null))->toBeTrue();
    expect($decider(0, $request, new Response(400), null))->toBeFalse();
    expect($decider(0, $request, new Response(404), null))->toBeFalse();
    expect($decider(0, $request, new Response(429), null))->toBeFalse();
    expect($decider(0, $request, null, null))->toBeFalse();
});

it('decider unwraps a 5xx out of a BadResponseException reason', function () {
    $decider = RetryMiddleware::decider();
    $request = new Request('POST', 'https://example.test/');
    $exception = new ServerException('boom', $request, new Response(502));

    expect($decider(0, $request, null, $exception))->toBeTrue();
});

it('exponentialBackoff grows with retry count', function () {
    $delay = RetryMiddleware::exponentialBackoff();

    // jitter is 0-500ms, so we check the floor of each step.
    expect($delay(0))->toBeGreaterThanOrEqual(1000);
    expect($delay(0))->toBeLessThan(1500 + 1);
    expect($delay(1))->toBeGreaterThanOrEqual(2000);
    expect($delay(2))->toBeGreaterThanOrEqual(4000);
    expect($delay(3))->toBeGreaterThanOrEqual(8000);
});
