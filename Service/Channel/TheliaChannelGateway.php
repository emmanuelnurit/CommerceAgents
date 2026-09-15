<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Channel;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Model\AgentChannelQuery;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\Tool\Channel\Gateway\ChannelGatewayInterface;

final readonly class TheliaChannelGateway implements ChannelGatewayInterface
{
    public function __construct(
        private ChannelConnectorConfigService $channelConfig,
    ) {
    }

    /**
     * Settings come from the centralized, per-connector configuration
     * (MYO-300: "configure once"), not from agent_channel.settings — a row
     * only records which connector an agent uses and whether it's enabled.
     */
    public function getEnabledChannelsForAgent(int $agentDefinitionId): array
    {
        $channels = [];
        foreach (AgentChannelQuery::create()->filterByAgentDefinitionId($agentDefinitionId)->filterByEnabled(1)->find() as $model) {
            $channels[] = [
                'id' => $model->getId(),
                'connectorCode' => $model->getConnectorCode(),
                'mode' => $model->getMode(),
                'settings' => $this->channelConfig->getSettings($model->getConnectorCode()),
            ];
        }

        return $channels;
    }

    public function stageMessage(int $channelId, string $connectorCode, ChannelMessage $message, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null || $ctx->adminId === null) {
            return ['error' => 'No conversation context'];
        }

        $change = (new AgentStagedChange())
            ->setConversationId($ctx->conversationId)
            ->setAdminId($ctx->adminId)
            ->setTargetType('channel_message')
            ->setTargetId($channelId)
            ->setPayloadBefore(json_encode([], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode([
                'connectorCode' => $connectorCode,
                'subject' => $message->subject,
                'body' => $message->body,
                'metadata' => $message->metadata,
            ], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        return [
            'changeId' => $change->getId(),
            'status' => StagedChangeData::STATUS_PENDING,
        ];
    }
}
