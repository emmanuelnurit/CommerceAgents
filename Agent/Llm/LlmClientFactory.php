<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class LlmClientFactory
{
    public const PROVIDERS = ['anthropic', 'mistral', 'openai-compatible'];

    public const DEFAULT_PROVIDER = 'mistral';

    public const DEFAULT_MODELS = [
        'anthropic' => 'claude-sonnet-5',
        'mistral' => 'ministral-3b-latest',
        'openai-compatible' => 'gpt-4.1-mini',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function create(string $provider): LlmClientInterface
    {
        return match ($provider) {
            'anthropic' => new AnthropicClient($this->httpClient),
            'mistral' => new MistralClient($this->httpClient),
            'openai-compatible' => new OpenAiCompatibleClient($this->httpClient),
            default => throw new \InvalidArgumentException(sprintf('Unknown LLM provider "%s"', $provider)),
        };
    }
}
