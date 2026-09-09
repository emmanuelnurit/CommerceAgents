<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CatalogGatewayInterface
{
    /**
     * @return array[] each product: {id, ref, title, description, price, promoPrice,
     *                 currency, url, imageUrl, inStock} — taxed prices in session currency
     */
    public function searchProducts(string $query, ?int $categoryId, ?float $minPrice, ?float $maxPrice, int $limit, ToolContext $ctx): array;

    /**
     * @return array|null {id, ref, title, description, url, imageUrl,
     *                    pses: [{id, attributes, price, promoPrice, stock}]}
     */
    public function getProductDetails(int $productId, ToolContext $ctx): ?array;
}
