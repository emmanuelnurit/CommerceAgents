<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Channel;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\ChannelMode;
use CommerceAgents\Tool\Channel\Gateway\ChannelGatewayInterface;

/**
 * The single LLM-facing channel tool (plan MYO-226 §3.6): the model only
 * writes a message, it never picks a channel or a connector. Which
 * channel(s) receive it, and whether they go out immediately or through
 * approval, is entirely decided by the agent's agent_channel configuration.
 */
final readonly class SendToChannelTool implements ToolInterface
{
    public function __construct(
        private ChannelGatewayInterface $channelGateway,
        private ChannelConnectorRegistry $registry,
        private ?AgentOutboundMessageLoggerInterface $outboundMessageLogger = null,
    ) {
    }

    public function getName(): string
    {
        return 'send_to_channel';
    }

    public function getDescription(): string
    {
        return 'Sends a message on the channel(s) configured for this agent (e.g. e-mail, Mattermost/Slack webhook). '
            .'The destination is fixed by the agent configuration, not chosen here. '
            .'Depending on that configuration, the message either leaves immediately or waits for BO approval.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Message body'],
                'subject' => ['type' => 'string', 'description' => 'Optional subject/title'],
            ],
            'required' => ['message'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CHANNELS_SEND;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->agentDefinitionId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        if ($ctx->agentDefinitionId === null) {
            return ['error' => 'send_to_channel requires a configurable-agent context'];
        }

        $body = trim((string) ($args['message'] ?? ''));
        if ($body === '') {
            return ['error' => 'message must not be empty'];
        }
        $subject = isset($args['subject']) && trim((string) $args['subject']) !== '' ? trim((string) $args['subject']) : null;

        $channels = $this->channelGateway->getEnabledChannelsForAgent($ctx->agentDefinitionId);
        if ($channels === []) {
            return ['error' => 'No channel configured for this agent'];
        }

        $message = new ChannelMessage($subject, $body);
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
     * Single write point for outbound-message traceability (MYO-328): one
     * agent_outbound_message row per channel result, whichever of the 3
     * branches above produced it.
     *
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
