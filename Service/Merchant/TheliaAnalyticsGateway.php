<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use BackOfficeDefaultTwigBundle\DTO\Dashboard\DateRange;
use BackOfficeDefaultTwigBundle\Repository\OrderRepository;
use BackOfficeDefaultTwigBundle\Repository\ProductRepository;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\AnalyticsGatewayInterface;
use Thelia\Model\CurrencyQuery;

final readonly class TheliaAnalyticsGateway implements AnalyticsGatewayInterface
{
    private const TOP_PRODUCTS_LIMIT = 5;

    public function __construct(
        private OrderRepository $orderRepository,
        private ProductRepository $productRepository,
    ) {
    }

    public function getSalesAnalytics(int $periodDays, ToolContext $ctx): array
    {
        $now = new \DateTimeImmutable('now');
        $range = new DateRange(
            from: $now->modify(\sprintf('-%d days', $periodDays - 1))->setTime(0, 0),
            to: $now->setTime(23, 59, 59),
            preset: DateRange::PRESET_THIRTY_DAYS,
        );

        return [
            'periodDays' => $periodDays,
            'revenue' => round($this->orderRepository->getRevenue($range), 2),
            'orderCount' => $this->orderRepository->countOrders($range),
            'currency' => CurrencyQuery::create()->filterByByDefault(true)->findOne()?->getCode() ?? 'EUR',
            'topProducts' => array_map(
                static fn (array $row): array => [
                    'ref' => $row['ref'],
                    'title' => $row['title'],
                    'unitsSold' => $row['quantity'],
                    'revenue' => round($row['revenue'], 2),
                ],
                $this->productRepository->findTopSellers($range, self::TOP_PRODUCTS_LIMIT, $ctx->locale),
            ),
            'statusBreakdown' => array_map(
                static fn (array $row): array => ['status' => $row['title'], 'count' => $row['count']],
                $this->orderRepository->getStatusBreakdown($ctx->locale),
            ),
        ];
    }
}
