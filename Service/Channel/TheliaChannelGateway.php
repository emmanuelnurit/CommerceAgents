<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Channel;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Model\AgentChannel;
use CommerceAgents\Model\AgentChannelQuery;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\Tool\Channel\Gateway\ChannelGatewayInterface;

final readonly class TheliaChannelGateway implements ChannelGatewayInterface
{
    public function __construct(
        private ChannelSettingsEncryptor $encryptor,
    ) {
    }

    public function getEnabledChannelsForAgent(int $agentDefinitionId): array
    {
        $channels = [];
        foreach (AgentChannelQuery::create()->filterByAgentDefinitionId($agentDefinitionId)->filterByEnabled(1)->find() as $model) {
            $channels[] = [
                'id' => $model->getId(),
                'connectorCode' => $model->getConnectorCode(),
                'mode' => $model->getMode(),
                'settings' => $this->encryptor->decrypt($model->getSettings()),
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

    /**
     * Encrypts and persists one channel's settings. Used by the future BO
     * configuration screen (issue E); exercised directly for now to prove
     * settings never reach the database in the clear.
     */
    public function saveChannel(int $agentDefinitionId, string $connectorCode, array $settings, string $mode, bool $enabled, ?int $channelId = null): AgentChannel
    {
        $model = $channelId !== null ? AgentChannelQuery::create()->findPk($channelId) : null;
        $model ??= new AgentChannel();

        $model
            ->setAgentDefinitionId($agentDefinitionId)
            ->setConnectorCode($connectorCode)
            ->setSettings($this->encryptor->encrypt($settings))
            ->setMode($mode)
            ->setEnabled($enabled ? 1 : 0);
        $model->save();

        return $model;
    }
}
