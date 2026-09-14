<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;

final readonly class UpdatePriceTool implements ToolInterface
{
    public function __construct(
        private StagingGatewayInterface $stagingGateway,
    ) {
    }

    public function getName(): string
    {
        return 'update_price';
    }

    public function getDescription(): string
    {
        return 'Creates a PENDING price change proposal for one product variant. '
            .'Nothing is modified until a human administrator approves it in the approval console. '
            .'Prices are pre-tax, in the store default currency. Use pse_id from get_pricing.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pse_id' => ['type' => 'integer', 'description' => 'Variant id as returned by get_pricing'],
                'new_price' => ['type' => 'number', 'description' => 'New pre-tax price in the store default currency'],
                'new_promo_price' => ['type' => 'number', 'description' => 'New pre-tax promo price (unchanged if omitted)'],
            ],
            'required' => ['pse_id', 'new_price'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::PRICING_WRITE;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $newPrice = (float) $args['new_price'];
        if ($newPrice <= 0) {
            return ['error' => 'new_price must be greater than zero'];
        }

        $newPromoPrice = isset($args['new_promo_price']) ? (float) $args['new_promo_price'] : null;
        if ($newPromoPrice !== null && $newPromoPrice <= 0) {
            return ['error' => 'new_promo_price must be greater than zero'];
        }

        $staged = $this->stagingGateway->stagePriceUpdate((int) $args['pse_id'], $newPrice, $newPromoPrice, $ctx);

        if (isset($staged['error'])) {
            return $staged;
        }

        return [
            'staged_change' => $staged,
            'message' => 'Price change proposal recorded. It requires human approval in the approval console before anything is applied.',
        ];
    }
}
