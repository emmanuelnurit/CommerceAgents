<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\CommerceAgents;

/**
 * Module settings. Credentials, base URL and model are kept per provider so
 * the merchant can switch the active provider without retyping anything.
 */
final readonly class AgentConfigService
{
    public function getProvider(): string
    {
        $provider = (string) CommerceAgents::getConfigValue('provider', LlmClientFactory::DEFAULT_PROVIDER);

        return \in_array($provider, LlmClientFactory::PROVIDERS, true) ? $provider : LlmClientFactory::DEFAULT_PROVIDER;
    }

    public function getLlmConfig(?string $provider = null): LlmConfig
    {
        $provider ??= $this->getProvider();

        return new LlmConfig(
            provider: $provider,
            model: $this->getModel($provider),
            apiKey: $this->getApiKey($provider),
            baseUrl: $this->getBaseUrl($provider),
        );
    }

    public function getApiKey(string $provider): string
    {
        return (string) CommerceAgents::getConfigValue(self::providerKey('api_key', $provider), '');
    }

    public function hasApiKey(string $provider): bool
    {
        return $this->getApiKey($provider) !== '';
    }

    public function getBaseUrl(string $provider): ?string
    {
        return CommerceAgents::getConfigValue(self::providerKey('base_url', $provider)) ?: null;
    }

    public function getModel(string $provider): string
    {
        $default = LlmClientFactory::DEFAULT_MODELS[$provider] ?? LlmClientFactory::DEFAULT_MODELS[LlmClientFactory::DEFAULT_PROVIDER];

        return (string) (CommerceAgents::getConfigValue(self::providerKey('model', $provider)) ?: $default);
    }

    public function setProviderSettings(string $provider, ?string $apiKey, string $baseUrl, string $model): void
    {
        if ($apiKey !== null && $apiKey !== '') {
            CommerceAgents::setConfigValue(self::providerKey('api_key', $provider), $apiKey);
        }
        CommerceAgents::setConfigValue(self::providerKey('base_url', $provider), trim($baseUrl));
        CommerceAgents::setConfigValue(self::providerKey('model', $provider), trim($model));
    }

    public function setProvider(string $provider): void
    {
        CommerceAgents::setConfigValue('provider', \in_array($provider, LlmClientFactory::PROVIDERS, true) ? $provider : LlmClientFactory::DEFAULT_PROVIDER);
    }

    /**
     * Run before the module default switched from Anthropic to Mistral: an
     * installation living on the implicit Anthropic default keeps it, stored
     * explicitly so the new default never changes its behaviour.
     */
    public function freezeImplicitProviderBeforeMistralDefault(): void
    {
        $stored = (string) CommerceAgents::getConfigValue('provider', '');
        $hasAnthropicKey = $this->hasApiKey('anthropic')
            || (string) CommerceAgents::getConfigValue('api_key', '') !== '';

        $frozen = self::providerToFreeze($stored, $hasAnthropicKey);
        if ($frozen !== null) {
            CommerceAgents::setConfigValue('provider', $frozen);
        }
    }

    /**
     * Decision table of the freeze: an explicit provider always wins, an
     * Anthropic key without explicit provider is frozen on Anthropic, a
     * virgin installation gets the new Mistral default.
     */
    public static function providerToFreeze(string $storedProvider, bool $hasAnthropicApiKey): ?string
    {
        if ($storedProvider !== '') {
            return null;
        }

        return $hasAnthropicApiKey ? 'anthropic' : null;
    }

    /**
     * Moves the single-provider settings of module 0.1.x to the per-provider keys.
     */
    public function migrateLegacySingleProviderSettings(): void
    {
        $provider = $this->getProvider();
        foreach (['api_key', 'base_url', 'model'] as $setting) {
            $legacy = (string) CommerceAgents::getConfigValue($setting, '');
            if ($legacy === '' || CommerceAgents::getConfigValue(self::providerKey($setting, $provider))) {
                continue;
            }
            CommerceAgents::setConfigValue(self::providerKey($setting, $provider), $legacy);
        }
    }

    public static function providerKey(string $setting, string $provider): string
    {
        return $setting.'_'.str_replace('-', '_', $provider);
    }

    /**
     * Monthly LLM spend limit in USD, 0 for no limit.
     */
    public function getMonthlyBudgetUsd(): float
    {
        return max(0.0, (float) CommerceAgents::getConfigValue('monthly_budget_usd', '0'));
    }

    public function getBudgetWarningPercent(): int
    {
        return max(0, min(100, (int) CommerceAgents::getConfigValue('budget_warning_percent', '80')));
    }

    public function isBudgetBlocking(): bool
    {
        return (bool) CommerceAgents::getConfigValue('budget_block', true);
    }

    public function setBudget(float $monthlyBudgetUsd, int $warningPercent, bool $blocking): void
    {
        CommerceAgents::setConfigValue('monthly_budget_usd', number_format(max(0.0, $monthlyBudgetUsd), 4, '.', ''));
        CommerceAgents::setConfigValue('budget_warning_percent', (string) max(0, min(100, $warningPercent)));
        CommerceAgents::setConfigValue('budget_block', $blocking ? '1' : '0');
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
