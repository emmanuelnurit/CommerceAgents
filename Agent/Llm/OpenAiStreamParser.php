<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Turns an OpenAI-style chat-completions SSE stream into LlmEvents. Token
 * usage, when the provider sends it (final chunk), is reported on TURN_END.
 */
final readonly class OpenAiStreamParser
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return \Generator<LlmEvent>
     */
    public function parse(ResponseInterface $response): \Generator
    {
        if ($response->getStatusCode() >= 400) {
            $body = json_decode($response->getContent(false), true);
            $message = $body['error']['message'] ?? $body['message'] ?? sprintf('Provider returned HTTP %d', $response->getStatusCode());
            yield LlmEvent::error(\is_string($message) ? $message : json_encode($message, \JSON_THROW_ON_ERROR));

            return;
        }

        $buffer = '';
        $stopReason = null;
        $inputTokens = 0;
        $outputTokens = 0;
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

                if (\is_array($payload['usage'] ?? null)) {
                    $inputTokens = (int) ($payload['usage']['prompt_tokens'] ?? $inputTokens);
                    $outputTokens = (int) ($payload['usage']['completion_tokens'] ?? $outputTokens);
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

        yield LlmEvent::turnEnd($stopReason, $inputTokens, $outputTokens);
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
