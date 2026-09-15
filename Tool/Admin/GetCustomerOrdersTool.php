<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CustomerOrdersGatewayInterface;

/**
 * Admin-side per-customer order history (MYO-286 item 2): get_analytics only
 * gives the shop-wide aggregate, this completes the 360° customer view
 * started by get_customer_profile with the detail an aggregate cannot give.
 */
final readonly class GetCustomerOrdersTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    public function __construct(
        private CustomerOrdersGatewayInterface $customerOrdersGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_customer_orders';
    }

    public function getDescription(): string
    {
        return 'List the order history of a given customer by id: reference, date, status and total for each order. '
            .'For merchant/support use, not the customer-facing get_orders.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Customer id, e.g. from get_customer_profile'],
                'limit' => ['type' => 'integer', 'description' => 'Max orders to return (default 10, max 50)'],
            ],
            'required' => ['customer_id'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::ORDERS_READ;
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

        $limit = min(max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);

        return ['orders' => $this->customerOrdersGateway->getOrdersForCustomer($customerId, $limit, $ctx)];
    }
}
