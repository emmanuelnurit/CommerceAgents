<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AnthropicClient implements LlmClientInterface
{
    private const DEFAULT_BASE_URL = 'https://api.anthropic.com';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function streamChat(array $messages, array $toolSpecs, string $system, LlmConfig $config): \Generator
    {
        $baseUrl = rtrim($config->baseUrl ?: self::DEFAULT_BASE_URL, '/');

        $body = [
            'model' => $config->model,
            'max_tokens' => $config->maxTokens,
            'temperature' => $config->temperature,
            'stream' => true,
            'messages' => $this->convertMessages($messages),
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }
        if ($toolSpecs !== []) {
            $body['tools'] = $toolSpecs;
        }

        $response = $this->httpClient->request('POST', $baseUrl.'/v1/messages', [
            'headers' => [
                'x-api-key' => $config->apiKey,
                'anthropic-version' => self::API_VERSION,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body, \JSON_THROW_ON_ERROR),
        ]);

        yield from $this->parseStream($response);
    }

    private function convertMessages(array $messages): array
    {
        $converted = [];

        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                $block = [
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolCallId,
                    'content' => json_encode($message->toolResult, \JSON_THROW_ON_ERROR),
                ];
                $last = $converted !== [] ? $converted[array_key_last($converted)] : null;
                if ($last !== null && $last['role'] === 'user' && \is_array($last['content']) && ($last['content'][0]['type'] ?? null) === 'tool_result') {
                    $converted[array_key_last($converted)]['content'][] = $block;
                } else {
                    $converted[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }

            if ($message->role === 'assistant' && $message->toolCalls !== []) {
                $content = [];
                if ($message->content !== '') {
                    $content[] = ['type' => 'text', 'text' => $message->content];
                }
                foreach ($message->toolCalls as $toolCall) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall->id,
                        'name' => $toolCall->name,
                        'input' => $toolCall->arguments === [] ? new \stdClass() : $toolCall->arguments,
                    ];
                }
                $converted[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }

            $converted[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $converted;
    }

    /**
     * @return \Generator<LlmEvent>
     */
    private function parseStream(object $response): \Generator
    {
        $buffer = '';
        $stopReason = null;
        $currentTool = null;

        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();

            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newlinePosition), "\r");
                $buffer = substr($buffer, $newlinePosition + 1);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $payload = json_decode(substr($line, 6), true);
                if (!\is_array($payload)) {
                    continue;
                }

                switch ($payload['type'] ?? '') {
                    case 'content_block_start':
                        if (($payload['content_block']['type'] ?? '') === 'tool_use') {
                            $currentTool = [
                                'id' => $payload['content_block']['id'],
                                'name' => $payload['content_block']['name'],
                                'json' => '',
                            ];
                        }
                        break;

                    case 'content_block_delta':
                        $delta = $payload['delta'] ?? [];
                        if (($delta['type'] ?? '') === 'text_delta') {
                            yield LlmEvent::textDelta($delta['text'] ?? '');
                        } elseif (($delta['type'] ?? '') === 'input_json_delta' && $currentTool !== null) {
                            $currentTool['json'] .= $delta['partial_json'] ?? '';
                        }
                        break;

                    case 'content_block_stop':
                        if ($currentTool !== null) {
                            $arguments = json_decode($currentTool['json'] !== '' ? $currentTool['json'] : '{}', true) ?? [];
                            yield LlmEvent::toolCall(new LlmToolCall(
                                id: $currentTool['id'],
                                name: $currentTool['name'],
                                arguments: $arguments,
                            ));
                            $currentTool = null;
                        }
                        break;

                    case 'message_delta':
                        $stopReason = $payload['delta']['stop_reason'] ?? $stopReason;
                        break;

                    case 'error':
                        yield LlmEvent::error($payload['error']['message'] ?? 'Unknown provider error');

                        return;
                }
            }
        }

        yield LlmEvent::turnEnd($stopReason);
    }
}
