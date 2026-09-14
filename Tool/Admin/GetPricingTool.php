<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CatalogAdminGatewayInterface;

final readonly class GetPricingTool implements ToolInterface
{
    public function __construct(
        private CatalogAdminGatewayInterface $catalogAdminGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_pricing';
    }

    public function getDescription(): string
    {
        return 'Get the catalog prices of one product, per variant: pre-tax price, promo price '
            .'and promo flag in the store default currency.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'integer', 'description' => 'Product id as returned by get_listings'],
            ],
            'required' => ['product_id'],
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
        $pricing = $this->catalogAdminGateway->getPricing((int) $args['product_id'], $ctx);

        if ($pricing === null) {
            return ['error' => 'Product not found'];
        }

        return ['pricing' => $pricing];
    }
}
