<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface OrderGatewayInterface
{
    /**
     * @return array[] each order: {ref, date, status, totalAmount, currency, url}
     */
    public function getOrders(int $customerId, int $limit, ToolContext $ctx): array;
}
