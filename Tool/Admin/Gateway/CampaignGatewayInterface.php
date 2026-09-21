<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CampaignGatewayInterface
{
    /**
     * @return array {coupons: [{code, title, enabled, expirationDate, usageLeft}],
     *               sales: [{title, active, startDate, endDate}]}
     */
    public function getCampaigns(bool $activeOnly, ToolContext $ctx): array;

    /**
     * Whether the given product is currently attached to an active catalog
     * sale (MYO-473): raises the urgency of a low-stock restock proposal.
     */
    public function isProductOnActiveSale(int $productId, ToolContext $ctx): bool;
}
