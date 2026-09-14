<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CatalogGatewayInterface
{
    /**
     * @return array{products: list<array>, matchedCategory: array|null} each product:
     *         {id, ref, title, description, categories, price, promoPrice, currency,
     *          url, imageUrl, inStock} — taxed prices in session currency, promoPrice
     *         null unless the product is actually discounted
     */
    public function searchProducts(
        ?string $query,
        ?int $categoryId,
        ?float $minPrice,
        ?float $maxPrice,
        bool $promoOnly,
        int $limit,
        ToolContext $ctx,
    ): array;

    /**
     * @return array|null {id, ref, title, description, categories, url, imageUrl,
     *                    pses: [{id, ref, isDefault, attributes, label, price,
     *                            promoPrice, stock, inStock, imageUrl}]}
     */
    public function getProductDetails(int $productId, ToolContext $ctx): ?array;
}
