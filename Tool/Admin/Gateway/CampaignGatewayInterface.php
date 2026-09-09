<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CampaignGatewayInterface
{
    /**
     * @return array {coupons: [{code, title, enabled, expirationDate, usageLeft}],
     *                sales: [{title, active, startDate, endDate}]}
     */
    public function getCampaigns(bool $activeOnly, ToolContext $ctx): array;
}
