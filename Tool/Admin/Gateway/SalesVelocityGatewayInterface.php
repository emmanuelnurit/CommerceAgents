<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface SalesVelocityGatewayInterface
{
    /**
     * Units sold per week for one product variant over a sliding window
     * ending now (MYO-473).
     *
     * @return array{pseId: int, pseRef: string, productId: int, productRef: string,
     *               quantity: float, weeks: int, unitsSold: float, velocityPerWeek: float,
     *               windowStart: string, windowEnd: string} or {error: string} when the variant is unknown
     */
    public function getSalesVelocity(int $pseId, int $weeks, ToolContext $ctx): array;
}
