<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CustomerAdminGatewayInterface;

/**
 * Admin-side counterpart of get_my_profile (MYO-286 item 1): "what do we know
 * about this customer" for a merchant agent, given a customer_id rather than
 * the caller's own session. Exposes only what a support/sales conversation
 * needs — never the full Customer entity (MYO-286 architecture constraint 4).
 */
final readonly class GetCustomerProfileTool implements ToolInterface
{
    public function __construct(
        private CustomerAdminGatewayInterface $customerAdminGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_customer_profile';
    }

    public function getDescription(): string
    {
        return 'Get the profile of a given customer by id: name, email, default address, registration date '
            .'and a link to their admin record. For merchant/support use, not the customer-facing get_my_profile.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer id, e.g. from get_customer_orders or an order lookup'],
            ],
            'required' => ['customer_id'],
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

        $profile = $this->customerAdminGateway->getCustomerProfile($customerId, $ctx->locale);

        if ($profile === null) {
            return ['error' => \sprintf('No customer found with id %d', $customerId)];
        }

        return ['profile' => $profile];
    }
}
