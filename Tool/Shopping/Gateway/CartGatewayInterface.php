<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CartGatewayInterface
{
    /**
     * @return array cart state after the addition:
     *               {items: [{productId, title, quantity, unitTaxedPrice, totalTaxedPrice}],
     *               totalTaxedAmount, currency, itemCount}
     */
    public function addToCart(int $productId, ?int $productSaleElementsId, int $quantity, ToolContext $ctx): array;

    public function getCart(ToolContext $ctx): array;
}
