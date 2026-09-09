<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

final readonly class LlmConfig
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $apiKey,
        public ?string $baseUrl = null,
        public int $maxTokens = 1024,
        public float $temperature = 1.0,
    ) {
    }
}
