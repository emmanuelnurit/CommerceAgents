<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

/**
 * Builds the LLM client of a provider. Behind an interface so agent runs can
 * be executed against a scripted client in tests, without any network call.
 */
interface LlmClientFactoryInterface
{
    public function create(string $provider): LlmClientInterface;
}
