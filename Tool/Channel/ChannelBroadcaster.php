<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Channel;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\ChannelMode;
use CommerceAgents\Tool\Channel\Gateway\ChannelGatewayInterface;

/**
 * Fan-out-and-log send path (extracted from SendToChannelTool for MYO-335):
 * resolves the agent's enabled channels, dispatches through the connector or
 * the draft staging path, and logs one agent_outbound_message row per channel
 * result. Shared by SendToChannelTool (LLM-facing tool call) and any
 * BO-triggered resend, so a "resend this report" action goes through the
 * exact same delivery/audit path instead of a second, drifting
 * implementation.
 */
final readonly class ChannelBroadcaster
{
    public function __construct(
        private ChannelGatewayInterface $channelGateway,
        private ChannelConnectorRegistry $registry,
        private ?AgentOutboundMessageLoggerInterface $outboundMessageLogger = null,
    ) {
    }

    /**
     * @return array{results: list<array{channelId: int, status?: string, error?: string, changeId?: int}>, error?: string}
     */
    public function broadcast(int $agentDefinitionId, ChannelMessage $message, ToolContext $ctx): array
    {
        $channels = $this->channelGateway->getEnabledChannelsForAgent($agentDefinitionId);
        if ($channels === []) {
            return ['results' => [], 'error' => 'No channel configured for this agent'];
        }

        $results = [];
        foreach ($channels as $channel) {
            $connector = $this->registry->get($channel['connectorCode']);
            if ($connector === null) {
                $result = ['channelId' => $channel['id'], 'error' => \sprintf('Unknown connector "%s"', $channel['connectorCode'])];
                $results[] = $result;
                $this->logOutbound($ctx, $channel, $result);
                continue;
            }

            if ($channel['mode'] === ChannelMode::DIRECT) {
                try {
                    $connector->send($message, $channel['settings']);
                    $result = ['channelId' => $channel['id'], 'status' => 'sent'];
                } catch (ChannelException $exception) {
                    $result = ['channelId' => $channel['id'], 'error' => $exception->getMessage()];
                }
                $results[] = $result;
                $this->logOutbound($ctx, $channel, $result);
                continue;
            }

            $staged = $this->channelGateway->stageMessage($channel['id'], $channel['connectorCode'], $message, $ctx);
            $result = $staged + ['channelId' => $channel['id']];
            $results[] = $result;
            $this->logOutbound($ctx, $channel, $result);
        }

        return ['results' => $results];
    }

    /**
     * @param array{id: int, connectorCode: string, mode: string, settings: array}   $channel
     * @param array{channelId: int, status?: string, error?: string, changeId?: int} $result
     */
    private function logOutbound(ToolContext $ctx, array $channel, array $result): void
    {
        if ($this->outboundMessageLogger === null) {
            return;
        }

        $status = match (true) {
            isset($result['error']) => AgentOutboundMessageLoggerInterface::STATUS_FAILED,
            ($result['status'] ?? null) === 'sent' => AgentOutboundMessageLoggerInterface::STATUS_SENT,
            default => AgentOutboundMessageLoggerInterface::STATUS_STAGED,
        };

        $recipient = $channel['settings']['to'] ?? $channel['settings']['url'] ?? null;

        $this->outboundMessageLogger->log(
            $ctx,
            $channel['connectorCode'],
            \is_string($recipient) ? $recipient : null,
            $status,
            $result['error'] ?? null,
        );
    }
}
