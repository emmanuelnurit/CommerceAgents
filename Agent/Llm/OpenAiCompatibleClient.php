<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenAiCompatibleClient implements LlmClientInterface
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com';

    /** Aligned on AbstractWebhookChannelConnector::TIMEOUT_SECONDS (MYO-284 B2). */
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
            // Asks the provider for a final usage chunk (OpenAI, OpenRouter, Groq, vLLM...).
            'stream_options' => ['include_usage' => true],
            'messages' => OpenAiMessageConverter::convert($messages, $system),
        ];
        if ($toolSpecs !== []) {
            $body['tools'] = OpenAiMessageConverter::convertTools($toolSpecs);
        }

        $response = $this->httpClient->request('POST', $baseUrl.'/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer '.$config->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body, \JSON_THROW_ON_ERROR),
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        yield from (new OpenAiStreamParser($this->httpClient))->parse($response);
    }
}
