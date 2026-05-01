<?php

namespace markhuot\craftai\agent\providers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use markhuot\craftai\tools\ToolDescriptor;

class AnthropicProvider implements LlmProvider
{
    private readonly ClientInterface $http;

    public function __construct(
        string $apiKey,
        private readonly string $model = 'claude-sonnet-4-20250514',
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
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

        $response = $this->http->request('POST', 'v1/messages', ['json' => $body]);

        /** @var array{id: string, content: list<array<string, mixed>>, stop_reason: string|null} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return new ProviderResponse(
            id: $payload['id'],
            content: $payload['content'],
            stopReason: $payload['stop_reason'] ?? 'end_turn',
            raw: $payload,
        );
    }
}
