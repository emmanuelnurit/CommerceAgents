<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CustomerOrdersGatewayInterface
{
    /**
     * @return array[] each order: {ref, date, status, statusCode, totalAmount, currency, adminUrl}
     */
    public function getOrdersForCustomer(int $customerId, int $limit, ToolContext $ctx): array;
}
