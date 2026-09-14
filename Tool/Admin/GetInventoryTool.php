<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CatalogAdminGatewayInterface;

final readonly class GetInventoryTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private CatalogAdminGatewayInterface $catalogAdminGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_inventory';
    }

    public function getDescription(): string
    {
        return 'List product variants with their stock quantity, lowest stock first. '
            .'Use low_stock_threshold to only get variants at or below a quantity.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'low_stock_threshold' => ['type' => 'number', 'description' => 'Only variants with stock at or below this value'],
                'limit' => ['type' => 'integer', 'description' => 'Max results (default 20, max 100)'],
            ],
            'required' => [],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CATALOG_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $inventory = $this->catalogAdminGateway->getInventory(
            lowStockThreshold: isset($args['low_stock_threshold']) ? (float) $args['low_stock_threshold'] : null,
            limit: min(max(1, (int) ($args['limit'] ?? self::DEFAULT_LIMIT)), self::MAX_LIMIT),
            ctx: $ctx,
        );

        return ['count' => \count($inventory), 'inventory' => $inventory];
    }
}
