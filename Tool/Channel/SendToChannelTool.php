<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Channel;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Tool\Channel\Gateway\ChannelGatewayInterface;

/**
 * The single LLM-facing channel tool (plan MYO-226 §3.6): the model only
 * writes a message, it never picks a channel or a connector. Which
 * channel(s) receive it, and whether they go out immediately or through
 * approval, is entirely decided by the agent's agent_channel configuration.
 */
final readonly class SendToChannelTool implements ToolInterface
{
    private ChannelBroadcaster $broadcaster;

    public function __construct(
        private ChannelGatewayInterface $channelGateway,
        private ChannelConnectorRegistry $registry,
        private ?AgentOutboundMessageLoggerInterface $outboundMessageLogger = null,
    ) {
        $this->broadcaster = new ChannelBroadcaster($channelGateway, $registry, $outboundMessageLogger);
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

        $outcome = $this->broadcaster->broadcast($ctx->agentDefinitionId, new ChannelMessage($subject, $body), $ctx);

        return isset($outcome['error']) ? ['error' => $outcome['error']] : ['results' => $outcome['results']];
    }
}
