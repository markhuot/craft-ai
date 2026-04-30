<?php

namespace markhuot\craftai\agent;

use GuzzleHttp\Client;

class AnthropicClient
{
    private readonly Client $http;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-sonnet-4-20250514',
    ) {
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/',
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
        ]);
    }

    /**
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     * @param list<array{name: string, description: string, input_schema: array<string, mixed>}> $tools
     * @return array{id: string, role: string, content: list<array<string, mixed>>, stop_reason: string|null}
     */
    public function createMessage(array $messages, array $tools = [], string $system = ''): array
    {
        /** @var array<string, mixed> $body */
        $body = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $body['tools'] = $tools;
        }

        if ($system !== '') {
            $body['system'] = $system;
        }

        $response = $this->http->post('v1/messages', [
            'json' => $body,
        ]);

        /** @var array{id: string, role: string, content: list<array<string, mixed>>, stop_reason: string|null} */
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
