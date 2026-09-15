<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Service\Catalog\ProductThumbnailProvider;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Cart\DTO\CartItemAddDTO;
use Thelia\Domain\Cart\Exception\NotEnoughStockException;
use Thelia\Domain\Catalog\Product\PSEFacade;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\Cart;

final readonly class TheliaCartGateway implements CartGatewayInterface
{
    public function __construct(
        private CartFacade $cartFacade,
        private PSEFacade $pseFacade,
        private TaxEngine $taxEngine,
        private RequestStack $requestStack,
        private ProductThumbnailProvider $thumbnailProvider,
    ) {
    }

    public function addToCart(int $productId, ?int $productSaleElementsId, int $quantity, ToolContext $ctx): array
    {
        if ($productSaleElementsId === null) {
            $productSaleElementsId = $this->pseFacade->getDefaultPSE($productId)?->getId();
            if ($productSaleElementsId === null) {
                throw new ToolException(\sprintf('Product %d not found or not purchasable', $productId));
            }
        }

        $cart = $this->cartFacade->getOrCreateFromSession();

        try {
            $this->cartFacade->addItem(new CartItemAddDTO($cart, $productId, $productSaleElementsId, $quantity));
        } catch (NotEnoughStockException) {
            throw new ToolException('Not enough stock for the requested quantity');
        } catch (\Exception $exception) {
            throw new ToolException('Could not add product to cart: '.$exception->getMessage());
        }

        return $this->cartState($cart);
    }

    public function getCart(ToolContext $ctx): array
    {
        return $this->snapshot();
    }

    /**
     * Same payload as getCart(), without a tool context: the front chat widget
     * paints its cart preview on the very first render, before any agent call.
     */
    public function snapshot(): array
    {
        $cart = $this->cartFacade->getCartFromSession();

        if ($cart === null) {
            return ['items' => [], 'totalTaxedAmount' => 0.0, 'currency' => $this->currencyCode(), 'itemCount' => 0];
        }

        return $this->cartState($cart);
    }

    private function cartState(Cart $cart): array
    {
        $country = $this->taxEngine->getDeliveryCountry();
        $locale = $this->requestStack->getMainRequest()?->getSession()?->getLang()?->getLocale() ?? 'fr_FR';

        $items = [];
        $itemCount = 0;
        foreach ($cart->getCartItems() as $cartItem) {
            $productId = (int) $cartItem->getProductId();
            $itemCount += (int) $cartItem->getQuantity();
            $items[] = [
                'productId' => $productId,
                'title' => $cartItem->getProduct()->setLocale($locale)->getTitle(),
                'quantity' => (int) $cartItem->getQuantity(),
                // getTaxedPrice() ignores the promo flag, while Cart::getTaxedAmount()
                // honours it: mixing both showed lines above the cart total.
                'unitTaxedPrice' => round($cartItem->getRealTaxedPrice($country), 2),
                'totalTaxedPrice' => round($cartItem->getTotalRealTaxedPrice($country), 2),
                'imageUrl' => $this->thumbnailProvider->urlFor($productId),
            ];
        }

        return [
            'items' => $items,
            'totalTaxedAmount' => round($cart->getTaxedAmount($country), 2),
            'currency' => $this->currencyCode(),
            'itemCount' => $itemCount,
        ];
    }

    private function currencyCode(): string
    {
        $session = $this->requestStack->getMainRequest()?->getSession();

        return $session instanceof \Thelia\Core\HttpFoundation\Session\Session
            ? $session->getCurrency()->getCode()
            : 'EUR';
    }
}
