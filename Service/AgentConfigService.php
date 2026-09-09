<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

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
        return new LlmConfig(
            provider: $this->getProvider(),
            model: (string) CommerceAgents::getConfigValue('model', 'claude-sonnet-5'),
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
}
