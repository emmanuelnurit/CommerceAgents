<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface AnalyticsGatewayInterface
{
    /**
     * @return array {periodDays, revenue, orderCount, currency,
     *               topProducts: [{ref, title, unitsSold, revenue}],
     *               statusBreakdown: [{status, count}]}
     */
    public function getSalesAnalytics(int $periodDays, ToolContext $ctx): array;
}
