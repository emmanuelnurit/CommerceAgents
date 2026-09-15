<?php

declare(strict_types=1);

namespace CommerceAgents\Channel;

use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Same DI pattern as ToolRegistry / StagedChangeManager's applier registry:
 * any service tagged 'commerce_agents.channel_connector' is indexed by code.
 */
final class ChannelConnectorRegistry
{
    /** @var array<string, ChannelConnectorInterface> */
    private array $connectors = [];

    /**
     * @param iterable<ChannelConnectorInterface> $connectors
     */
    public function __construct(
        #[TaggedIterator('commerce_agents.channel_connector')] iterable $connectors = [],
    ) {
        foreach ($connectors as $connector) {
            $this->register($connector);
        }
    }

    public function register(ChannelConnectorInterface $connector): void
    {
        $this->connectors[$connector->getCode()] = $connector;
    }

    public function get(string $code): ?ChannelConnectorInterface
    {
        return $this->connectors[$code] ?? null;
    }

    public function has(string $code): bool
    {
        return isset($this->connectors[$code]);
    }

    /**
     * Connector descriptions for the BO configuration screen (issue E).
     *
     * @return list<array{code: string, label: string, settingsSchema: array}>
     */
    public function describeAll(): array
    {
        $descriptions = [];
        foreach ($this->connectors as $connector) {
            $descriptions[] = [
                'code' => $connector->getCode(),
                'label' => $connector->getLabel(),
                'settingsSchema' => $connector->getSettingsSchema(),
            ];
        }

        return $descriptions;
    }
}
