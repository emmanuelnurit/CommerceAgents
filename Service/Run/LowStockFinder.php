<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Sale elements at or under a stock threshold (plan MYO-226 §3.3 point 4:
 * "stock bas: requête sur les stocks sous un seuil"), for the `low_stock`
 * cron trigger. The threshold is per-trigger, read from `conditions` JSON by
 * the caller (wizard MYO-227 §4.3 step 3, "champ de seuil").
 */
final class LowStockFinder
{
    public const DEFAULT_THRESHOLD = 5;

    /**
     * @return ProductSaleElements[]
     */
    public function find(int $threshold): array
    {
        return iterator_to_array(
            ProductSaleElementsQuery::create()
                ->filterByVisible(true)
                ->filterByQuantity(max(0, $threshold), Criteria::LESS_EQUAL)
                ->find(),
        );
    }
}
