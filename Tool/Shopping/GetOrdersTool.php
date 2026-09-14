<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\OrderGatewayInterface;

final readonly class GetOrdersTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 20;

    public function __construct(
        private OrderGatewayInterface $orderGateway,
        private bool $ordersEnabled = true,
    ) {
    }

    public function getName(): string
    {
        return 'get_orders';
    }

    public function getDescription(): string
    {
        return 'List the recent orders of the logged-in customer: reference, date, status and total. '
            .'Only available when the customer is logged in.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max orders to return (default 5, max 20)'],
            ],
            'required' => [],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::ORDERS_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin && $this->ordersEnabled && $ctx->customerId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        if ($ctx->customerId === null) {
            return ['error' => 'Customer must be logged in to see orders'];
        }

        $limit = min(max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT);

        return ['orders' => $this->orderGateway->getOrders($ctx->customerId, $limit, $ctx)];
    }
}
