<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mistral "La Plateforme" chat completions. The wire format is OpenAI-like,
 * with two differences that make a dedicated client necessary: unknown request
 * fields are rejected (no `stream_options`), and tool call ids must be
 * 9 alphanumeric characters. Usage is sent in the last stream chunk.
 */
final readonly class MistralClient implements LlmClientInterface
{
    private const DEFAULT_BASE_URL = 'https://api.mistral.ai';

    /** Aligned on WebhookChannelConnector::TIMEOUT_SECONDS (MYO-284 B2). */
    private const TIMEOUT_SECONDS = 10;

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
            'messages' => OpenAiMessageConverter::convert($messages, $system, MistralToolCallId::normalize(...)),
        ];
        if ($toolSpecs !== []) {
            $body['tools'] = OpenAiMessageConverter::convertTools($toolSpecs);
            $body['tool_choice'] = 'auto';
        }

        $response = $this->httpClient->request('POST', $baseUrl.'/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$config->apiKey,
                'Accept' => 'text/event-stream',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body, \JSON_THROW_ON_ERROR),
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        yield from (new OpenAiStreamParser($this->httpClient))->parse($response);
    }
}
