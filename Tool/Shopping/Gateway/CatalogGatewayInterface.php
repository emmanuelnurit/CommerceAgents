<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CatalogGatewayInterface
{
    /**
     * @return array{products: list<array>, matchedCategory: array|null} each product:
     *                                                                   {id, ref, title, description, categories, price, promoPrice, currency,
     *                                                                   url, imageUrl, inStock} — taxed prices in session currency, promoPrice
     *                                                                   null unless the product is actually discounted
     */
    public function searchProducts(
        ?string $query,
        ?int $categoryId,
        ?int $featureAvId,
        ?float $minPrice,
        ?float $maxPrice,
        bool $promoOnly,
        int $limit,
        ToolContext $ctx,
    ): array;

    /**
     * Variants carrying one option value ("Couleur: Orange"), across products.
     *
     * @return list<array{id: int, ref: string, productId: int, productTitle: string,
     *                    url: string|null, label: string, price: float|null,
     *                    promoPrice: float|null, currency: string|null,
     *                    inStock: bool, imageUrl: string|null}>
     */
    public function searchVariants(
        ?string $query,
        int $attributeAvId,
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
     *                    promoPrice, stock, inStock, imageUrl}]}
     */
    public function getProductDetails(int $productId, ToolContext $ctx): ?array;

    /**
     * The product's default (primary) category id, used to find other
     * products in the "same range" for cross-sell — null when the product
     * does not exist or carries no category.
     */
    public function getDefaultCategoryId(int $productId): ?int;
}
