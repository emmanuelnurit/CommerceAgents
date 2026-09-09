<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class LlmClientFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function create(string $provider): LlmClientInterface
    {
        return match ($provider) {
            'anthropic' => new AnthropicClient($this->httpClient),
            'openai-compatible' => new OpenAiCompatibleClient($this->httpClient),
            default => throw new \InvalidArgumentException(sprintf('Unknown LLM provider "%s"', $provider)),
        };
    }
}
