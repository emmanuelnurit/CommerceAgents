<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Catalog\Product\PSEFacade;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Thelia\Model\ProductSaleElements;

final readonly class TheliaCatalogGateway implements CatalogGatewayInterface
{
    public function __construct(
        private DataAccessService $dataAccessService,
        private PSEFacade $pseFacade,
        private TaxEngine $taxEngine,
        private SecurityContext $securityContext,
        private RequestStack $requestStack,
    ) {
    }

    public function searchProducts(string $query, ?int $categoryId, ?float $minPrice, ?float $maxPrice, int $limit, ToolContext $ctx): array
    {
        if (trim($query) === '') {
            return [];
        }

        $filters = [
            'title' => trim($query),
            'visible' => true,
            'itemsPerPage' => $limit,
            'page' => 1,
            'order[position]' => 'asc',
            'order[ref]' => 'asc',
        ];
        if ($categoryId !== null) {
            $filters['productCategories.category.id'] = $categoryId;
        }
        if ($minPrice !== null) {
            $filters['productSaleElements.productPrices.price[gte]'] = $minPrice;
        }
        if ($maxPrice !== null) {
            $filters['productSaleElements.productPrices.price[lte]'] = $maxPrice;
        }

        $response = $this->dataAccessService->resources('/api/front/products', $filters, 'jsonld');

        $products = [];
        foreach ($response['hydra:member'] ?? [] as $member) {
            $products[] = $this->mapMember($member);
        }

        return $products;
    }

    public function getProductDetails(int $productId, ToolContext $ctx): ?array
    {
        $response = $this->dataAccessService->resources(
            '/api/front/products',
            ['id' => $productId, 'visible' => true, 'itemsPerPage' => 1],
            'jsonld',
        );

        $member = $response['hydra:member'][0] ?? null;
        if ($member === null) {
            return null;
        }

        $product = $this->mapMember($member);
        $product['pses'] = array_map(
            fn (ProductSaleElements $pse): array => $this->mapPse($pse, $ctx->locale),
            $this->pseFacade->getByProduct($productId),
        );

        return $product;
    }

    private function mapMember(array $member): array
    {
        $productId = (int) ($member['id'] ?? 0);
        $defaultPse = $this->pseFacade->getDefaultPSE($productId);
        $pricing = $defaultPse !== null ? $this->taxedPricing($defaultPse) : null;

        $inStock = false;
        foreach ($member['productSaleElements'] ?? [] as $pse) {
            if (((int) ($pse['quantity'] ?? 0)) > 0) {
                $inStock = true;
                break;
            }
        }

        return [
            'id' => $productId,
            'ref' => (string) ($member['ref'] ?? ''),
            'title' => (string) ($member['i18ns']['title'] ?? ''),
            'description' => mb_substr(trim(strip_tags((string) ($member['i18ns']['description'] ?? ''))), 0, 200),
            'price' => $pricing['price'] ?? null,
            'promoPrice' => $pricing['promoPrice'] ?? null,
            'currency' => $this->session()?->getCurrency()->getCode(),
            'url' => $member['publicUrl'] ?? null,
            'imageUrl' => $this->firstImageUrl($productId),
            'inStock' => $inStock,
        ];
    }

    private function mapPse(ProductSaleElements $pse, string $locale): array
    {
        $pricing = $this->taxedPricing($pse);

        $attributes = [];
        foreach ($pse->getAttributeCombinations() as $combination) {
            $attributes[$combination->getAttribute()->setLocale($locale)->getTitle()] =
                $combination->getAttributeAv()->setLocale($locale)->getTitle();
        }

        return [
            'id' => $pse->getId(),
            'ref' => $pse->getRef(),
            'isDefault' => $pse->isDefault(),
            'attributes' => $attributes,
            'price' => $pricing['price'],
            'promoPrice' => $pse->getPromo() ? $pricing['promoPrice'] : null,
            'stock' => (float) $pse->getQuantity(),
        ];
    }

    /**
     * Taxed price in the session currency — the virtual columns must be set
     * before calling getTaxedPrice() (see ProductSaleElementsAccessService).
     *
     * @return array{price: float, promoPrice: float}
     */
    private function taxedPricing(ProductSaleElements $pse): array
    {
        $session = $this->session();
        $currency = $session?->getCurrency();
        $taxCountry = $this->taxEngine->getDeliveryCountry();

        $discount = $this->securityContext->hasCustomerUser()
            ? (float) $this->securityContext->getCustomerUser()->getDiscount()
            : 0.0;

        $prices = $pse->getPricesByCurrency($currency, $discount);
        $pse->setVirtualColumn('price_PRICE', $prices->getPrice());
        $pse->setVirtualColumn('price_PROMO_PRICE', $prices->getPromoPrice());

        return [
            'price' => round($pse->getTaxedPrice($taxCountry), 2),
            'promoPrice' => round($pse->getTaxedPromoPrice($taxCountry), 2),
        ];
    }

    private function firstImageUrl(int $productId): ?string
    {
        $response = $this->dataAccessService->resources(
            '/api/front/product_images',
            ['product.id' => $productId, 'visible' => true, 'itemsPerPage' => 1],
            'jsonld',
        );

        return $response['hydra:member'][0]['fileUrl'] ?? null;
    }

    private function session(): ?\Thelia\Core\HttpFoundation\Session\Session
    {
        $session = $this->requestStack->getMainRequest()?->getSession();

        return $session instanceof \Thelia\Core\HttpFoundation\Session\Session ? $session : null;
    }
}
