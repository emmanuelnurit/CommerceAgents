<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenAiCompatibleClient implements LlmClientInterface
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com';

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
            'messages' => $this->convertMessages($messages, $system),
        ];
        if ($toolSpecs !== []) {
            $body['tools'] = array_map(
                static fn (array $spec): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $spec['name'],
                        'description' => $spec['description'],
                        'parameters' => $spec['input_schema'],
                    ],
                ],
                $toolSpecs,
            );
        }

        $response = $this->httpClient->request('POST', $baseUrl.'/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$config->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body, \JSON_THROW_ON_ERROR),
        ]);

        yield from $this->parseStream($response);
    }

    private function convertMessages(array $messages, string $system): array
    {
        $converted = [];
        if ($system !== '') {
            $converted[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                $converted[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message->toolCallId,
                    'content' => json_encode($message->toolResult, \JSON_THROW_ON_ERROR),
                ];
                continue;
            }

            if ($message->role === 'assistant' && $message->toolCalls !== []) {
                $converted[] = [
                    'role' => 'assistant',
                    'content' => $message->content !== '' ? $message->content : null,
                    'tool_calls' => array_map(
                        static fn (LlmToolCall $toolCall): array => [
                            'id' => $toolCall->id,
                            'type' => 'function',
                            'function' => [
                                'name' => $toolCall->name,
                                'arguments' => json_encode($toolCall->arguments === [] ? new \stdClass() : $toolCall->arguments, \JSON_THROW_ON_ERROR),
                            ],
                        ],
                        $message->toolCalls,
                    ),
                ];
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
        if ($response->getStatusCode() >= 400) {
            $body = json_decode($response->getContent(false), true);
            $message = $body['error']['message'] ?? sprintf('Provider returned HTTP %d', $response->getStatusCode());
            yield LlmEvent::error($message);

            return;
        }

        $buffer = '';
        $stopReason = null;
        $pendingToolCalls = [];

        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();

            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $newlinePosition), "\r");
                $buffer = substr($buffer, $newlinePosition + 1);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);
                if ($data === '[DONE]') {
                    continue;
                }

                $payload = json_decode($data, true);
                if (!\is_array($payload)) {
                    continue;
                }

                if (isset($payload['error'])) {
                    yield LlmEvent::error($payload['error']['message'] ?? 'Unknown provider error');

                    return;
                }

                $choice = $payload['choices'][0] ?? null;
                if ($choice === null) {
                    continue;
                }

                $delta = $choice['delta'] ?? [];

                if (($delta['content'] ?? null) !== null && $delta['content'] !== '') {
                    yield LlmEvent::textDelta($delta['content']);
                }

                foreach ($delta['tool_calls'] ?? [] as $toolCallDelta) {
                    $index = $toolCallDelta['index'] ?? 0;
                    if (!isset($pendingToolCalls[$index])) {
                        $pendingToolCalls[$index] = ['id' => '', 'name' => '', 'arguments' => ''];
                    }
                    if (isset($toolCallDelta['id'])) {
                        $pendingToolCalls[$index]['id'] = $toolCallDelta['id'];
                    }
                    if (isset($toolCallDelta['function']['name'])) {
                        $pendingToolCalls[$index]['name'] .= $toolCallDelta['function']['name'];
                    }
                    if (isset($toolCallDelta['function']['arguments'])) {
                        $pendingToolCalls[$index]['arguments'] .= $toolCallDelta['function']['arguments'];
                    }
                }

                if (($choice['finish_reason'] ?? null) !== null) {
                    $stopReason = $this->normalizeStopReason($choice['finish_reason']);
                }
            }
        }

        ksort($pendingToolCalls);
        foreach ($pendingToolCalls as $pending) {
            $arguments = json_decode($pending['arguments'] !== '' ? $pending['arguments'] : '{}', true) ?? [];
            yield LlmEvent::toolCall(new LlmToolCall(
                id: $pending['id'],
                name: $pending['name'],
                arguments: $arguments,
            ));
        }

        yield LlmEvent::turnEnd($stopReason);
    }

    private function normalizeStopReason(string $finishReason): string
    {
        return match ($finishReason) {
            'tool_calls' => 'tool_use',
            'stop' => 'end_turn',
            'length' => 'max_tokens',
            default => $finishReason,
        };
    }
}
