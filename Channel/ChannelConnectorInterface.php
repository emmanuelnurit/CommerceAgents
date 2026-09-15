<?php

declare(strict_types=1);

namespace CommerceAgents\Channel;

/**
 * A channel a configurable agent can send messages through. Implementations
 * are auto-tagged 'commerce_agents.channel_connector' (see
 * CommerceAgents::configureServices()) and picked up by
 * ChannelConnectorRegistry without any further wiring — a third-party module
 * only has to implement this interface (plan MYO-226 §3.6).
 */
interface ChannelConnectorInterface
{
    /**
     * Stable identifier stored in agent_channel.connector_code, e.g. 'mail', 'webhook'.
     */
    public function getCode(): string;

    /**
     * Human-readable label for the BO configuration screen.
     */
    public function getLabel(): string;

    /**
     * JSON Schema describing the connector's settings, used to render and
     * validate the BO configuration form (issue E).
     */
    public function getSettingsSchema(): array;

    /**
     * Sends a short probe message with the given settings and reports whether
     * it went through, without throwing.
     */
    public function test(array $settings): ConnectorTestResult;

    /**
     * @throws ChannelException when the message could not be delivered
     */
    public function send(ChannelMessage $message, array $settings): void;
}
