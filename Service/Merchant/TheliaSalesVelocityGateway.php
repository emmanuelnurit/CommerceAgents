<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\SalesVelocityGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\OrderProductQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class TheliaSalesVelocityGateway implements SalesVelocityGatewayInterface
{
    public function getSalesVelocity(int $pseId, int $weeks, ToolContext $ctx): array
    {
        $pse = ProductSaleElementsQuery::create()->findPk($pseId);
        if ($pse === null) {
            return ['error' => 'Variant not found'];
        }

        $product = $pse->getProduct();
        $windowStart = (new \DateTime())->modify(\sprintf('-%d days', $weeks * 7));
        $windowEnd = new \DateTime();

        $unitsSold = (float) (OrderProductQuery::create()
            ->filterByProductSaleElementsId($pseId)
            ->useOrderQuery()
                ->filterByCreatedAt($windowStart->format('Y-m-d 00:00:00'), Criteria::GREATER_EQUAL)
                ->filterByStatusId(OrderStatusQuery::getPaidStatusIdList(), Criteria::IN)
            ->endUse()
            ->withColumn('SUM(`order_product`.QUANTITY)', 'TOTAL')
            ->select('TOTAL')
            ->findOne() ?? 0);

        return [
            'pseId' => $pse->getId(),
            'pseRef' => $pse->getRef(),
            'productId' => $product->getId(),
            'productRef' => $product->getRef(),
            'quantity' => (float) $pse->getQuantity(),
            'weeks' => $weeks,
            'unitsSold' => $unitsSold,
            'velocityPerWeek' => round($unitsSold / $weeks, 2),
            'windowStart' => $windowStart->format('Y-m-d'),
            'windowEnd' => $windowEnd->format('Y-m-d'),
        ];
    }
}
