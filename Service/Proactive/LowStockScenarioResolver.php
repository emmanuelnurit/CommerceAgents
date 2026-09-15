<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Proactive;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;

/**
 * Scenario 5 (plan MYO-236, lot 4): on a product page whose real stock is
 * below the BO-configurable threshold, warn the visitor with the exact
 * remaining count — read from GetProductDetailsTool's own data source
 * (CatalogGatewayInterface::getProductDetails) at the moment of the signal,
 * never cached or guessed. Skips a product already in the cart.
 * ProactiveGuard's per-session scenario dedupe already gives "do not repeat
 * on the same visit" for free.
 */
final readonly class LowStockScenarioResolver implements ProactiveScenarioResolverInterface
{
    public const SIGNAL_TYPE = 'low_stock_viewed';

    public function __construct(
        private CatalogGatewayInterface $catalogGateway,
        private CartGatewayInterface $cartGateway,
        private int $lowStockThreshold,
    ) {
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        if ($signal->type !== self::SIGNAL_TYPE) {
            return null;
        }

        $productId = isset($signal->context['product_id']) ? (int) $signal->context['product_id'] : null;
        if ($productId === null || $productId <= 0 || $this->isInCart($productId, $context)) {
            return null;
        }

        $product = $this->catalogGateway->getProductDetails($productId, $context);
        if ($product === null) {
            return null;
        }

        $stock = $this->lowestRealStock($product, $signal->context);
        if ($stock === null || $stock >= $this->lowStockThreshold) {
            return null;
        }

        return new ProactiveMessage(
            message: \sprintf('Il ne reste que %d en stock pour %s.', $stock, $product['title']),
            productId: $productId,
            productTitle: $product['title'],
            productUrl: $product['url'] ?? null,
            productImageUrl: $product['imageUrl'] ?? null,
            productInStock: true,
            productStockLabel: \sprintf('Plus que %d en stock', $stock),
        );
    }

    private function isInCart(int $productId, ToolContext $context): bool
    {
        foreach ($this->cartGateway->getCart($context)['items'] ?? [] as $item) {
            if ((int) ($item['productId'] ?? 0) === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signal may name a specific variant being viewed (pse_id); otherwise
     * the lowest positive stock across variants is the most conservative
     * "about to run out" reading for the product.
     */
    private function lowestRealStock(array $product, array $signalContext): ?int
    {
        $pseId = isset($signalContext['pse_id']) ? (int) $signalContext['pse_id'] : null;
        $lowest = null;

        foreach ($product['pses'] ?? [] as $pse) {
            $stock = (int) ($pse['stock'] ?? 0);

            if ($pseId !== null) {
                if ((int) ($pse['id'] ?? 0) === $pseId) {
                    return $stock > 0 ? $stock : null;
                }

                continue;
            }

            if ($stock > 0 && ($lowest === null || $stock < $lowest)) {
                $lowest = $stock;
            }
        }

        return $lowest;
    }
}
