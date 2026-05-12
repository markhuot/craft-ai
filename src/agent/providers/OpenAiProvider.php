<?php

namespace markhuot\craftai\agent\providers;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use markhuot\craftai\tools\ToolDescriptor;

/**
 * Translates the AgentLoop's Anthropic-shaped message history to/from OpenAI's
 * Chat Completions format. Internal canonical format stays Anthropic-style so
 * MessageRecord history works regardless of which provider is configured.
 */
class OpenAiProvider implements LlmProvider
{
    private readonly ClientInterface $http;

    public function __construct(
        string $apiKey,
        private readonly string $model = 'gpt-4o',
        ?ClientInterface $http = null,
        ?string $baseUrl = null,
    ) {
        if ($http !== null) {
            $this->http = $http;
            return;
        }

        $stack = HandlerStack::create();
        RetryMiddleware::attach($stack);
        $this->http = new Client([
            'handler' => $stack,
            'base_uri' => rtrim($baseUrl ?? 'https://api.openai.com', '/').'/',
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'content-type' => 'application/json',
            ],
        ]);
    }

    public function createMessage(array $messages, array $tools = [], ?string $system = null): ProviderResponse
    {
        $openAiMessages = $this->translateMessagesOut($messages, $system);

        /** @var array<string, mixed> $body */
        $body = [
            'model' => $this->model,
            'messages' => $openAiMessages,
        ];

        if ($tools !== []) {
            $body['tools'] = array_map(
                static fn (ToolDescriptor $d): array => $d->toOpenAiTool(),
                $tools,
            );
        }

        MessageEncodingValidator::assertValid($body);

        $response = $this->http->request('POST', 'v1/chat/completions', ['json' => $body]);

        /** @var array{id: string, choices: list<array{message: array<string, mixed>, finish_reason: string|null}>, usage?: array{prompt_tokens?: int, completion_tokens?: int}} $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $choice = $payload['choices'][0];
        $message = $choice['message'];
        $finishReason = $choice['finish_reason'] ?? 'stop';

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new ProviderResponse(
            id: $payload['id'],
            content: $this->translateAssistantIn($message),
            stopReason: $this->translateStopReason($finishReason),
            raw: $payload,
            inputTokens: isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens']) ? $usage['prompt_tokens'] : null,
            outputTokens: isset($usage['completion_tokens']) && is_int($usage['completion_tokens']) ? $usage['completion_tokens'] : null,
        );
    }

    /**
     * @param list<array{role: string, content: string|list<array<string, mixed>>}> $messages
     * @return list<array<string, mixed>>
     */
    private function translateMessagesOut(array $messages, ?string $system): array
    {
        $out = [];

        if ($system !== null && $system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = $msg['content'];

            if (is_string($content)) {
                $out[] = ['role' => $role, 'content' => $content];
                continue;
            }

            if ($role === 'assistant') {
                $text = '';
                $reasoning = '';
                $toolCalls = [];
                foreach ($content as $block) {
                    $type = $block['type'] ?? '';
                    if ($type === 'text') {
                        $blockText = $block['text'] ?? '';
                        $text .= is_string($blockText) ? $blockText : '';
                    } elseif ($type === 'thinking') {
                        $blockText = $block['thinking'] ?? '';
                        $reasoning .= is_string($blockText) ? $blockText : '';
                    } elseif ($type === 'tool_use') {
                        $toolCalls[] = [
                            'id' => $block['id'],
                            'type' => 'function',
                            'function' => [
                                'name' => $block['name'],
                                'arguments' => json_encode($block['input'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
                            ],
                        ];
                    }
                }
                // Skip assistant turns with no real content. These come from
                // historical `error` blocks (saved by AgentJob when a provider
                // call failed) — replaying them as empty assistant messages
                // poisons the conversation and gets rejected by strict
                // OpenAI-compatible providers.
                if ($text === '' && $reasoning === '' && $toolCalls === []) {
                    continue;
                }

                $entry = ['role' => 'assistant'];
                if ($text !== '') {
                    $entry['content'] = $text;
                }
                if ($reasoning !== '') {
                    // DeepSeek (and other reasoning-capable providers exposed via
                    // OpenAI-compatible APIs) require the assistant's prior
                    // `reasoning_content` to be echoed back on follow-up turns.
                    $entry['reasoning_content'] = $reasoning;
                }
                if ($toolCalls !== []) {
                    $entry['tool_calls'] = $toolCalls;
                }
                $out[] = $entry;
                continue;
            }

            // user role: text blocks become user content; tool_result blocks become role=tool messages
            $userText = '';
            foreach ($content as $block) {
                $type = $block['type'] ?? '';
                if ($type === 'text') {
                    $blockText = $block['text'] ?? '';
                    $userText .= is_string($blockText) ? $blockText : '';
                } elseif ($type === 'tool_result') {
                    // Strip non-text blocks (e.g. image content from
                    // generate_image tools) — strict OpenAI-compatible
                    // providers like DeepSeek reject array content on tool
                    // messages and reject array content on user follow-ups
                    // too, so the safe cross-provider shape is text-only.
                    // The image is still saved as a Craft asset and the
                    // tool's text payload contains the asset id + url, so
                    // the agent can refer back to the image even though
                    // OpenAI-compat models can't visually re-inspect it.
                    $out[] = [
                        'role' => 'tool',
                        'tool_call_id' => $block['tool_use_id'] ?? '',
                        'content' => $this->toolResultText($block['content'] ?? ''),
                    ];
                }
            }
            if ($userText !== '') {
                $out[] = ['role' => 'user', 'content' => $userText];
            }
        }

        return $out;
    }

    /**
     * Render a tool_result `content` value as plain text. Strings pass
     * through unchanged; an array of Anthropic-shaped blocks gets its text
     * blocks concatenated and its non-text blocks (e.g. images) dropped —
     * see {@see translateMessagesOut} for why.
     */
    private function toolResultText(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return json_encode($content, JSON_THROW_ON_ERROR);
        }

        $parts = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array<string, mixed>>
     */
    private function translateAssistantIn(array $message): array
    {
        $blocks = [];

        $reasoning = $message['reasoning_content'] ?? null;
        if (is_string($reasoning) && $reasoning !== '') {
            $blocks[] = ['type' => 'thinking', 'thinking' => $reasoning];
        }

        $textContent = $message['content'] ?? null;
        if (is_string($textContent) && $textContent !== '') {
            $blocks[] = ['type' => 'text', 'text' => $textContent];
        }

        $toolCalls = $message['tool_calls'] ?? [];
        if (is_array($toolCalls)) {
            foreach ($toolCalls as $call) {
                if (! is_array($call)) {
                    continue;
                }
                $function = is_array($call['function'] ?? null) ? $call['function'] : [];
                $args = $function['arguments'] ?? '{}';
                $decoded = is_string($args) ? json_decode($args, true) : $args;
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => is_string($call['id'] ?? null) ? $call['id'] : '',
                    'name' => is_string($function['name'] ?? null) ? $function['name'] : '',
                    'input' => is_array($decoded) ? $decoded : [],
                ];
            }
        }

        return $blocks;
    }

    private function translateStopReason(string $finishReason): string
    {
        return match ($finishReason) {
            'tool_calls', 'function_call' => 'tool_use',
            'length' => 'max_tokens',
            'stop' => 'end_turn',
            default => $finishReason,
        };
    }
}
