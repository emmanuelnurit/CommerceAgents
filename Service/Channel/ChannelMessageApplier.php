<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Channel;

use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Model\AgentChannelQuery;
use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;

/**
 * Approving a pending 'channel_message' staged change is the moment the
 * message actually leaves (plan MYO-226 §3.6 draft mode): StagedChangeManager
 * calls apply() only after a human approved it in the BO console.
 */
final readonly class ChannelMessageApplier implements ChangeApplierInterface
{
    public function __construct(
        private ChannelConnectorRegistry $registry,
        private ChannelSettingsEncryptor $encryptor,
    ) {
    }

    public function getTargetType(): string
    {
        return 'channel_message';
    }

    public function apply(StagedChangeData $change): void
    {
        $channel = AgentChannelQuery::create()->findPk($change->targetId);
        if ($channel === null) {
            throw new \RuntimeException(\sprintf('Channel %d not found', $change->targetId));
        }

        $connectorCode = (string) ($change->payloadAfter['connectorCode'] ?? $channel->getConnectorCode());
        $connector = $this->registry->get($connectorCode);
        if ($connector === null) {
            throw new \RuntimeException(\sprintf('Unknown channel connector "%s"', $connectorCode));
        }

        $message = new ChannelMessage(
            $change->payloadAfter['subject'] ?? null,
            (string) ($change->payloadAfter['body'] ?? ''),
            $change->payloadAfter['metadata'] ?? [],
        );

        $connector->send($message, $this->encryptor->decrypt($channel->getSettings()));
    }
}
