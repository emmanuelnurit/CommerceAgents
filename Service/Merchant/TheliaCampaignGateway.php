<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\CampaignGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\CouponQuery;
use Thelia\Model\SaleProductQuery;
use Thelia\Model\SaleQuery;

final readonly class TheliaCampaignGateway implements CampaignGatewayInterface
{
    public function getCampaigns(bool $activeOnly, ToolContext $ctx): array
    {
        $couponQuery = CouponQuery::create()->orderByExpirationDate(Criteria::DESC)->limit(50);
        if ($activeOnly) {
            $couponQuery
                ->filterByIsEnabled(true)
                ->filterByExpirationDate(new \DateTime('now'), Criteria::GREATER_EQUAL);
        }

        $coupons = [];
        foreach ($couponQuery->find() as $coupon) {
            $coupons[] = [
                'code' => $coupon->getCode(),
                'title' => $coupon->setLocale($ctx->locale)->getTitle(),
                'enabled' => (bool) $coupon->getIsEnabled(),
                'expirationDate' => $coupon->getExpirationDate()?->format('Y-m-d'),
                'usageLeft' => $coupon->getMaxUsage(),
            ];
        }

        $saleQuery = SaleQuery::create()->orderByStartDate(Criteria::DESC)->limit(50);
        if ($activeOnly) {
            $saleQuery->filterByActive(true);
        }

        $sales = [];
        foreach ($saleQuery->find() as $sale) {
            $sales[] = [
                'title' => $sale->setLocale($ctx->locale)->getTitle(),
                'active' => (bool) $sale->getActive(),
                'startDate' => $sale->getStartDate()?->format('Y-m-d'),
                'endDate' => $sale->getEndDate()?->format('Y-m-d'),
            ];
        }

        return ['coupons' => $coupons, 'sales' => $sales];
    }

    public function isProductOnActiveSale(int $productId, ToolContext $ctx): bool
    {
        return SaleProductQuery::create()
            ->filterByProductId($productId)
            ->useSaleQuery()
                ->filterByActive(true)
            ->endUse()
            ->exists();
    }
}
