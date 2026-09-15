<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Proactive;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;

/**
 * Scenario 2 (plan MYO-236, lot 4): on a product or category page, suggest
 * one promo product sharing the same range (default category) as the page
 * being viewed. Reuses the same CatalogGatewayInterface::searchProducts()
 * data as SearchProductsTool, so the price/discount shown is always the
 * real, live one — never approximated. ProactiveGuard already dedupes by
 * signal type for the session, which is exactly the "one suggestion per
 * product page, not repeated this session" rule from the ticket.
 */
final readonly class CrossSellPromoScenarioResolver implements ProactiveScenarioResolverInterface
{
    public const SIGNAL_TYPE = 'cross_sell_promo_viewed';

    private const SEARCH_LIMIT = 5;

    public function __construct(
        private CatalogGatewayInterface $catalogGateway,
    ) {
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        if ($signal->type !== self::SIGNAL_TYPE) {
            return null;
        }

        $viewedProductId = isset($signal->context['product_id']) ? (int) $signal->context['product_id'] : null;
        $categoryId = $this->resolveCategoryId($signal->context, $viewedProductId);

        if ($categoryId === null) {
            return null;
        }

        $search = $this->catalogGateway->searchProducts(
            query: null,
            categoryId: $categoryId,
            featureAvId: null,
            minPrice: null,
            maxPrice: null,
            promoOnly: true,
            limit: self::SEARCH_LIMIT,
            ctx: $context,
        );

        foreach ($search['products'] as $product) {
            $candidate = $this->asDiscountedCandidate($product, $viewedProductId);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveCategoryId(array $signalContext, ?int $viewedProductId): ?int
    {
        if (isset($signalContext['category_id'])) {
            return (int) $signalContext['category_id'];
        }

        if ($viewedProductId !== null) {
            return $this->catalogGateway->getDefaultCategoryId($viewedProductId);
        }

        return null;
    }

    private function asDiscountedCandidate(array $product, ?int $viewedProductId): ?ProactiveMessage
    {
        if ($viewedProductId !== null && (int) $product['id'] === $viewedProductId) {
            return null;
        }

        $price = $product['price'] ?? null;
        $promoPrice = $product['promoPrice'] ?? null;
        if ($price === null || $promoPrice === null || $price <= 0.0 || $promoPrice >= $price) {
            return null;
        }

        $discountPercent = (int) round((1 - $promoPrice / $price) * 100);
        if ($discountPercent <= 0) {
            return null;
        }

        $currency = $product['currency'] ?? 'EUR';

        return new ProactiveMessage(
            message: \sprintf(
                '%s est à -%d%% en ce moment, dans la même gamme que ce que vous consultez.',
                $product['title'],
                $discountPercent,
            ),
            productId: (int) $product['id'],
            productTitle: $product['title'],
            productUrl: $product['url'] ?? null,
            productImageUrl: $product['imageUrl'] ?? null,
            productPrice: $price,
            productPromoPrice: $promoPrice,
            productCurrency: $currency,
            productInStock: $product['inStock'] ?? null,
        );
    }
}
