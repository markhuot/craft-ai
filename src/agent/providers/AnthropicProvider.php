<?php

namespace markhuot\craftai\agent\providers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use markhuot\craftai\tools\ToolDescriptor;

class AnthropicProvider implements LlmProvider
{
    private readonly ClientInterface $http;

    public function __construct(
        string $apiKey,
        private readonly string $model = 'claude-sonnet-4-20250514',
        ?ClientInterface $http = null,
    ) {
        if ($http !== null) {
            $this->http = $http;
            return;
        }

        // Auto-created production client: attach retry middleware so transient
        // 5xx and connection blips don't bubble up as failed agent turns.
        // Tests that pass an explicit $http opt out (and can attach retry
        // themselves via {@see RetryMiddleware::attach} when they want to
        // exercise it).
        $stack = HandlerStack::create();
        RetryMiddleware::attach($stack);
        $this->http = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.anthropic.com/',
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
        ]);
    }

    public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
    {
        /** @var array<string, mixed> $body */
        $body = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $body['tools'] = array_map(
                static fn (ToolDescriptor $d): array => $d->toAnthropicTool(),
                $tools,
            );
        }

        if ($system !== null && $system !== '') {
            $body['system'] = $system;
        }

        MessageEncodingValidator::assertValid($body);

        $response = $this->http->request('POST', 'v1/messages', ['json' => $body]);

        /** @var array{id: string, content: list<array<string, mixed>>, stop_reason: string|null, usage?: array{input_tokens?: int, output_tokens?: int}} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new ProviderResponse(
            id: $payload['id'],
            content: $payload['content'],
            stopReason: $payload['stop_reason'] ?? 'end_turn',
            raw: $payload,
            inputTokens: isset($usage['input_tokens']) && is_int($usage['input_tokens']) ? $usage['input_tokens'] : null,
            outputTokens: isset($usage['output_tokens']) && is_int($usage['output_tokens']) ? $usage['output_tokens'] : null,
        );
    }
}
