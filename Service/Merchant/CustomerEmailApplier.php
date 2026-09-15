<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;

/**
 * Applies a pending 'customer_email' proposal (MYO-340) by sending it
 * through the mail connector with the recipient recorded on the proposal
 * itself -- never the shop's centrally configured channel (see
 * SendCustomerEmailTool doc). ChannelException (e.g. MYO-332's "no store
 * e-mail configured") already extends \RuntimeException, so it propagates
 * as-is to StagedChangeManager::approve(), which marks the change failed.
 */
final readonly class CustomerEmailApplier implements ChangeApplierInterface
{
    public function __construct(
        private ChannelConnectorRegistry $connectorRegistry,
    ) {
    }

    public function getTargetType(): string
    {
        return 'customer_email';
    }

    public function apply(StagedChangeData $change): void
    {
        $connector = $this->connectorRegistry->get('mail');
        if ($connector === null) {
            throw new \RuntimeException('No "mail" channel connector registered; cannot send this customer e-mail');
        }

        $after = $change->payloadAfter;
        $recipient = (string) ($after['recipient'] ?? '');
        $body = (string) ($after['body'] ?? '');
        $subject = isset($after['subject']) ? (string) $after['subject'] : null;

        // ChannelException already extends \RuntimeException (Channel/ChannelException.php),
        // so it propagates as-is to StagedChangeManager::approve() without rewrapping.
        $connector->send(new ChannelMessage($subject, $body), ['to' => $recipient]);
    }
}
