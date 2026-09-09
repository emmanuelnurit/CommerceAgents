<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;

final readonly class AddToCartTool implements ToolInterface
{
    private const MAX_QUANTITY = 10;

    public function __construct(
        private CartGatewayInterface $cartGateway,
        private bool $cartEnabled = true,
    ) {
    }

    public function getName(): string
    {
        return 'add_to_cart';
    }

    public function getDescription(): string
    {
        return 'Add a product to the current customer cart and return the updated cart state. '
            .'Use product_sale_elements_id when the customer chose a specific variant.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => ['type' => 'integer', 'description' => 'Product id as returned by search_products'],
                'product_sale_elements_id' => ['type' => 'integer', 'description' => 'Variant id from get_product_details (default variant if omitted)'],
                'quantity' => ['type' => 'integer', 'description' => 'Quantity to add (default 1, max 10)'],
            ],
            'required' => ['product_id'],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin && $this->cartEnabled;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $quantity = min(max(1, (int) ($args['quantity'] ?? 1)), self::MAX_QUANTITY);

        $cart = $this->cartGateway->addToCart(
            productId: (int) $args['product_id'],
            productSaleElementsId: isset($args['product_sale_elements_id']) ? (int) $args['product_sale_elements_id'] : null,
            quantity: $quantity,
            ctx: $ctx,
        );

        return ['cart' => $cart];
    }
}
