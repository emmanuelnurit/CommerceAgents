<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\SalesVelocityGatewayInterface;

final readonly class GetSalesVelocityTool implements ToolInterface
{
    private const DEFAULT_WEEKS = 4;
    private const MAX_WEEKS = 12;

    public function __construct(
        private SalesVelocityGatewayInterface $salesVelocityGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_sales_velocity';
    }

    public function getDescription(): string
    {
        return 'Get the sales velocity (units sold per week) of one product variant over a sliding window '
            .'ending today. Use pse_id from get_inventory. Never invent a figure: always quote '
            .'velocity_per_week exactly as returned.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pse_id' => ['type' => 'integer', 'description' => 'Variant id as returned by get_inventory'],
                'weeks' => ['type' => 'integer', 'description' => 'Sliding window size in weeks (default 4, max 12)'],
            ],
            'required' => ['pse_id'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::ANALYTICS_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $weeks = min(max(1, (int) ($args['weeks'] ?? self::DEFAULT_WEEKS)), self::MAX_WEEKS);

        $velocity = $this->salesVelocityGateway->getSalesVelocity((int) $args['pse_id'], $weeks, $ctx);

        if (isset($velocity['error'])) {
            return $velocity;
        }

        return ['velocity' => $velocity];
    }
}
