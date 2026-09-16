<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Service\Channel\ChannelSettingsEncryptor;

/**
 * Module settings. Credentials, base URL and model are kept per provider so
 * the merchant can switch the active provider without retyping anything.
 */
final readonly class AgentConfigService
{
    public function __construct(
        private ChannelSettingsEncryptor $encryptor,
    ) {
    }

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

    /**
     * LlmConfig of a configurable agent, honouring its own provider/model
     * overrides (NULL = module default). Read at every run so a change in the
     * back-office takes effect on the very next execution (plan MYO-226 §3.8).
     */
    public function getLlmConfigForAgent(?string $agentProvider, ?string $agentModel): LlmConfig
    {
        $provider = $agentProvider !== null && \in_array($agentProvider, LlmClientFactory::PROVIDERS, true)
            ? $agentProvider
            : $this->getProvider();

        return new LlmConfig(
            provider: $provider,
            model: $agentModel !== null && $agentModel !== '' ? $agentModel : $this->getModel($provider),
            apiKey: $this->getApiKey($provider),
            baseUrl: $this->getBaseUrl($provider),
        );
    }

    /**
     * MYO-276: the key is encrypted at rest (sodium secretbox, see
     * ChannelSettingsEncryptor) so a DB dump never carries a usable LLM
     * credential in the clear. An install upgraded from before this fix may
     * still hold a plaintext value; it is returned as-is (decryption fails
     * safely on non-ciphertext) and gets migrated to the encrypted form the
     * next time it is saved from the back office.
     */
    public function getApiKey(string $provider): string
    {
        $stored = (string) CommerceAgents::getConfigValue(self::providerKey('api_key', $provider), '');
        if ($stored === '') {
            return '';
        }

        try {
            return $this->encryptor->decryptString($stored);
        } catch (\RuntimeException) {
            return $stored;
        }
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
            CommerceAgents::setConfigValue(self::providerKey('api_key', $provider), $this->encryptor->encryptString($apiKey));
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
            $value = $setting === 'api_key' ? $this->encryptor->encryptString($legacy) : $legacy;
            CommerceAgents::setConfigValue(self::providerKey($setting, $provider), $value);
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
     * Max proactive solicitations per widget session (plan MYO-236, ProactiveGuard).
     */
    public function getMaxProactivePrompts(): int
    {
        return max(0, (int) CommerceAgents::getConfigValue('max_proactive_prompts', 3));
    }

    public function setMaxProactivePrompts(int $maxProactivePrompts): void
    {
        CommerceAgents::setConfigValue('max_proactive_prompts', (string) max(0, $maxProactivePrompts));
    }

    /**
     * Real stock level (per variant) under which scenario 5 (MYO-236 lot 4)
     * warns the visitor a product is running low.
     */
    public function getLowStockThreshold(): int
    {
        return max(1, (int) CommerceAgents::getConfigValue('low_stock_threshold', 5));
    }

    public function setLowStockThreshold(int $lowStockThreshold): void
    {
        CommerceAgents::setConfigValue('low_stock_threshold', (string) max(1, $lowStockThreshold));
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
// MYO-454 CTO re-verif AC2 2026-09-16T06:06:08Z
