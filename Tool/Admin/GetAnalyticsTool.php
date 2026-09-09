<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\AnalyticsGatewayInterface;

final readonly class GetAnalyticsTool implements ToolInterface
{
    private const DEFAULT_PERIOD_DAYS = 30;
    private const MAX_PERIOD_DAYS = 365;

    public function __construct(
        private AnalyticsGatewayInterface $analyticsGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_analytics';
    }

    public function getDescription(): string
    {
        return 'Get store sales analytics over a sliding period: revenue, order count, '
            .'top selling products and order status breakdown. Never invent figures: '
            .'always quote numbers exactly as returned.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period_days' => ['type' => 'integer', 'description' => 'Sliding period in days (default 30, max 365)'],
            ],
            'required' => [],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $periodDays = min(max(1, (int) ($args['period_days'] ?? self::DEFAULT_PERIOD_DAYS)), self::MAX_PERIOD_DAYS);

        return ['analytics' => $this->analyticsGateway->getSalesAnalytics($periodDays, $ctx)];
    }
}
