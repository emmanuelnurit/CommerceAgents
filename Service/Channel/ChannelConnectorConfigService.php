<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Channel;

use CommerceAgents\CommerceAgents;

/**
 * Centralized, per-connector settings ("configure once") for the BO channels
 * panel (MYO-300): one encrypted blob per connector code, stored through
 * Thelia's module config key/value store (same mechanism AgentConfigService
 * already uses for LLM provider API keys), not per agent_channel row. Every
 * agent that enables a given connector shares these settings — see
 * TheliaChannelGateway, which reads through this service instead of the
 * per-agent row.
 */
final readonly class ChannelConnectorConfigService
{
    public function __construct(
        private ChannelSettingsEncryptor $encryptor,
    ) {
    }

    /**
     * A payload that fails to decrypt (APP_SECRET rotated, corrupted row) is
     * treated as unconfigured rather than crashing the "Canaux" tab or the
     * agent runtime — same fail-safe as AgentConfigService::getApiKey().
     *
     * @return array<string, string>
     */
    public function getSettings(string $connectorCode): array
    {
        try {
            return $this->encryptor->decrypt(CommerceAgents::getConfigValue(self::key($connectorCode), ''));
        } catch (\RuntimeException) {
            return [];
        }
    }

    public function isConfigured(string $connectorCode): bool
    {
        return $this->getSettings($connectorCode) !== [];
    }

    /**
     * Merges freshly submitted values onto the currently stored settings: a
     * secret property (JSON Schema format "uri") left blank keeps its stored
     * value, exactly like the LLM provider API key field does, so admins
     * never have to re-paste a webhook URL to tweak an unrelated field.
     *
     * @param array<string, string> $submitted raw posted values keyed by schema property name
     */
    public function merge(string $connectorCode, array $submitted, array $schema): array
    {
        return self::mergeSettings($this->getSettings($connectorCode), $submitted, $schema);
    }

    /**
     * @param array<string, string> $current    currently stored settings
     * @param array<string, string> $submitted  raw posted values keyed by schema property name
     */
    public static function mergeSettings(array $current, array $submitted, array $schema): array
    {
        $secretProperties = self::secretProperties($schema);
        $merged = $current;

        foreach (array_keys($schema['properties'] ?? []) as $name) {
            $value = trim((string) ($submitted[$name] ?? ''));
            if ($value === '' && \in_array($name, $secretProperties, true)) {
                continue;
            }
            $merged[$name] = $value;
        }

        return $merged;
    }

    public function persist(string $connectorCode, array $settings): void
    {
        CommerceAgents::setConfigValue(self::key($connectorCode), $this->encryptor->encrypt($settings));
    }

    /**
     * @return list<string> names of the schema properties that hold a secret (never redisplayed in the BO form)
     */
    public static function secretProperties(array $schema): array
    {
        $secrets = [];
        foreach ($schema['properties'] ?? [] as $name => $definition) {
            if (($definition['format'] ?? null) === 'uri') {
                $secrets[] = $name;
            }
        }

        return $secrets;
    }

    private static function key(string $connectorCode): string
    {
        return \sprintf('channel_connector_%s_settings', $connectorCode);
    }
}
