<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CustomerAdminGatewayInterface;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;

/**
 * Sends an e-mail to a customer (MYO-340): the only tool this module offers
 * that reaches an actual shop customer rather than the merchant (contrast
 * with SendToChannelTool, whose destination is always the shop's own
 * configured channel -- see ChannelGatewayInterface doc). The model never
 * supplies an address: only a customer_id, resolved server-side via
 * CustomerAdminGatewayInterface (same gateway as get_customer_profile), so a
 * hallucinated or copied address can never receive mail. Always staged,
 * never direct -- see StagingGatewayInterface::stageCustomerEmail() doc.
 */
final readonly class SendCustomerEmailTool implements ToolInterface
{
    /**
     * Excerpt length for the agent_outbound_message trail (MYO-340 point 7),
     * matching the "≈280 characters" the ticket asks for.
     */
    private const EXCERPT_LENGTH = 280;

    public function __construct(
        private CustomerAdminGatewayInterface $customerAdminGateway,
        private StagingGatewayInterface $stagingGateway,
        private ?AgentOutboundMessageLoggerInterface $outboundMessageLogger = null,
    ) {
    }

    public function getName(): string
    {
        return 'send_email_to_customer';
    }

    public function getDescription(): string
    {
        return 'Creates a PENDING proposal to send an e-mail to a customer, identified by customer_id only -- '
            .'never supply an e-mail address yourself. Nothing is sent until a human administrator approves it '
            .'in the approval console.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer id, e.g. from get_customer_profile or an order lookup'],
                'subject' => ['type' => 'string', 'description' => 'E-mail subject; optional, a default is used when omitted'],
                'body' => ['type' => 'string', 'description' => 'E-mail body, plain text'],
            ],
            'required' => ['customer_id', 'body'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CUSTOMER_PROFILE_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $customerId = (int) $args['customer_id'];
        if ($customerId <= 0) {
            return ['error' => 'customer_id must be a positive integer'];
        }

        $body = trim((string) $args['body']);
        if ($body === '') {
            return ['error' => 'body must not be empty'];
        }

        $subject = isset($args['subject']) ? trim((string) $args['subject']) : null;
        $subject = $subject === '' ? null : $subject;

        $profile = $this->customerAdminGateway->getCustomerProfile($customerId, $ctx->locale);
        if ($profile === null) {
            return ['error' => \sprintf('No customer found with id %d', $customerId)];
        }

        $recipient = $profile['email'] ?? null;
        if (!\is_string($recipient) || $recipient === '') {
            return ['error' => \sprintf('Customer %d has no e-mail address on file', $customerId)];
        }

        $staged = $this->stagingGateway->stageCustomerEmail($customerId, $recipient, $subject, $body, $ctx);

        if (isset($staged['error'])) {
            return $staged;
        }

        $this->outboundMessageLogger?->log(
            $ctx,
            'mail',
            $recipient,
            AgentOutboundMessageLoggerInterface::STATUS_STAGED,
            null,
            $this->excerpt($body),
        );

        return [
            'staged_change' => $staged,
            'message' => 'Customer e-mail proposal recorded. It requires human approval in the approval console before anything is sent.',
        ];
    }

    private function excerpt(string $body): string
    {
        if (mb_strlen($body) <= self::EXCERPT_LENGTH) {
            return $body;
        }

        return mb_substr($body, 0, self::EXCERPT_LENGTH).'…';
    }
}
