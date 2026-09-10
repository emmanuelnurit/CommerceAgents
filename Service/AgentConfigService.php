<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\CommerceAgents;

final readonly class AgentConfigService
{
    public function getProvider(): string
    {
        return (string) CommerceAgents::getConfigValue('provider', 'anthropic');
    }

    public function getLlmConfig(): LlmConfig
    {
        $provider = $this->getProvider();
        $defaultModel = LlmClientFactory::DEFAULT_MODELS[$provider] ?? LlmClientFactory::DEFAULT_MODELS['anthropic'];

        return new LlmConfig(
            provider: $provider,
            model: (string) (CommerceAgents::getConfigValue('model') ?: $defaultModel),
            apiKey: (string) CommerceAgents::getConfigValue('api_key', ''),
            baseUrl: CommerceAgents::getConfigValue('base_url') ?: null,
        );
    }

    public function getAssistantName(): string
    {
        return (string) CommerceAgents::getConfigValue('assistant_name', 'Alex');
    }

    public function isFrontChatEnabled(): bool
    {
        return (bool) CommerceAgents::getConfigValue('enable_front_chat', true);
    }

    public function isCartEnabled(): bool
    {
        return (bool) CommerceAgents::getConfigValue('enable_cart', true);
    }

    public function isCheckoutEnabled(): bool
    {
        return (bool) CommerceAgents::getConfigValue('enable_checkout', true);
    }

    public function areOrdersEnabled(): bool
    {
        return (bool) CommerceAgents::getConfigValue('enable_orders', true);
    }

    public function getDailyMessageLimit(): int
    {
        return (int) CommerceAgents::getConfigValue('daily_message_limit', 200);
    }

    /**
     * @return int[] content ids holding the store policies (terms, shipping, returns)
     */
    public function getPolicyContentIds(): array
    {
        $raw = trim((string) CommerceAgents::getConfigValue('policy_content_ids', ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
}
