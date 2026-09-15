<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Channel\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelMessage;

interface ChannelGatewayInterface
{
    /**
     * Enabled channels configured for the given agent definition, settings
     * already decrypted. The channel to use is picked by this configuration,
     * never by the LLM (plan MYO-226 §3.6).
     *
     * @return list<array{id: int, connectorCode: string, mode: string, settings: array}>
     */
    public function getEnabledChannelsForAgent(int $agentDefinitionId): array;

    /**
     * Queues a message as a pending staged change instead of sending it
     * (agent_channel.mode = draft): nothing leaves until a human approves it
     * in the BO console.
     *
     * @return array{changeId?: int, status?: string, error?: string}
     */
    public function stageMessage(int $channelId, string $connectorCode, ChannelMessage $message, ToolContext $ctx): array;
}
