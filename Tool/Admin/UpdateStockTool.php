<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;

final readonly class UpdateStockTool implements ToolInterface
{
    public function __construct(
        private StagingGatewayInterface $stagingGateway,
    ) {
    }

    public function getName(): string
    {
        return 'update_stock';
    }

    public function getDescription(): string
    {
        return 'Creates a PENDING stock change proposal for one product variant. '
            .'Nothing is modified until a human administrator approves it in the approval console. '
            .'Use pse_id from get_inventory or get_pricing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pse_id' => ['type' => 'integer', 'description' => 'Variant id as returned by get_inventory'],
                'new_quantity' => ['type' => 'number', 'description' => 'New stock quantity (0 or more)'],
            ],
            'required' => ['pse_id', 'new_quantity'],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $newQuantity = (float) $args['new_quantity'];
        if ($newQuantity < 0) {
            return ['error' => 'new_quantity cannot be negative'];
        }

        $staged = $this->stagingGateway->stageStockUpdate((int) $args['pse_id'], $newQuantity, $ctx);

        if (isset($staged['error'])) {
            return $staged;
        }

        return [
            'staged_change' => $staged,
            'message' => 'Stock change proposal recorded. It requires human approval in the approval console before anything is applied.',
        ];
    }
}
