<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CatalogAdminGatewayInterface
{
    /**
     * @return array[] {id, ref, title, visible, position, categoryTitles, publicUrl, imageUrl}
     */
    public function getListings(?string $search, int $limit, int $offset, ToolContext $ctx): array;

    /**
     * @return array[] {productId, productRef, pseId, pseRef, title, quantity, isDefault, imageUrl} sorted by ascending stock
     */
    public function getInventory(?float $lowStockThreshold, int $limit, ToolContext $ctx): array;

    /**
     * @return array|null {productId, ref, title, pses: [{pseId, ref, price, promoPrice, promo, currency}]} catalog pre-tax prices
     */
    public function getPricing(int $productId, ToolContext $ctx): ?array;
}
