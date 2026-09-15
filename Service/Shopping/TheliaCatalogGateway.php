<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Catalog\ProductThumbnailProvider;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Security\SecurityContext;
use Thelia\Domain\Catalog\Product\PSEFacade;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class TheliaCatalogGateway implements CatalogGatewayInterface
{
    private const EXCERPT_LENGTH = 200;

    public function __construct(
        private DataAccessService $dataAccessService,
        private PSEFacade $pseFacade,
        private TaxEngine $taxEngine,
        private SecurityContext $securityContext,
        private RequestStack $requestStack,
        private ProductThumbnailProvider $thumbnailProvider,
        private CategoryGatewayInterface $categoryGateway,
    ) {
    }

    public function searchProducts(
        ?string $query,
        ?int $categoryId,
        ?int $featureAvId,
        ?float $minPrice,
        ?float $maxPrice,
        bool $promoOnly,
        int $limit,
        ToolContext $ctx,
    ): array {
        $query = trim((string) $query);
        [$minPrice, $maxPrice] = PriceRange::sane($minPrice, $maxPrice);

        $hasCriterion = $query !== '' || $categoryId !== null || $featureAvId !== null || $promoOnly
            || $minPrice !== null || $maxPrice !== null;

        if (!$hasCriterion) {
            return ['products' => [], 'matchedCategory' => null];
        }

        $products = $this->runSearch($query, $categoryId, $featureAvId, $minPrice, $maxPrice, $promoOnly, $limit, $ctx->locale);

        if ($products !== [] || $query === '') {
            return ['products' => $products, 'matchedCategory' => null];
        }

        // The keyword matched no title. Store catalogues title products after a
        // model name, so a type word ("chair", "sofa") only ever exists as a
        // category: drop the keyword and search the category instead — the one
        // that was asked for, or the closest one to the keyword itself.
        $category = $categoryId !== null
            ? $this->categoryGateway->findById($categoryId, $ctx->locale)
            : $this->categoryGateway->findByName($query, $ctx->locale);

        if ($category === null) {
            return ['products' => [], 'matchedCategory' => null];
        }

        return [
            'products' => $this->runSearch('', $category['id'], $featureAvId, $minPrice, $maxPrice, $promoOnly, $limit, $ctx->locale),
            'matchedCategory' => $category,
        ];
    }

    public function searchVariants(
        ?string $query,
        int $attributeAvId,
        ?int $categoryId,
        ?float $minPrice,
        ?float $maxPrice,
        bool $promoOnly,
        int $limit,
        ToolContext $ctx,
    ): array {
        [$minPrice, $maxPrice] = PriceRange::sane($minPrice, $maxPrice);
        $query = trim((string) $query);

        $search = ProductSaleElementsQuery::create()
            ->useAttributeCombinationQuery()
                ->filterByAttributeAvId($attributeAvId)
            ->endUse();

        $products = $search->useProductQuery()->filterByVisible(true);
        if ($query !== '') {
            $products->useI18nQuery($ctx->locale)
                ->filterByTitle('%'.$query.'%', Criteria::LIKE)
            ->endUse();
        }
        if ($categoryId !== null) {
            $products->useProductCategoryQuery()
                ->filterByCategoryId($categoryId)
            ->endUse();
        }
        $products->endUse();

        if ($promoOnly) {
            $search->filterByPromo(1);
        }

        $bounds = array_filter(['min' => $minPrice, 'max' => $maxPrice], static fn ($bound): bool => $bound !== null);
        if ($bounds !== []) {
            $search->useProductPriceQuery()
                ->filterByCurrencyId($this->currencyId())
                ->filterByPrice($bounds)
            ->endUse();
        }

        $variants = [];
        foreach ($search->orderByProductId()->limit(max(1, $limit))->find() as $pse) {
            $variants[] = $this->mapVariant($pse, $ctx->locale);
        }

        return $variants;
    }

    private function mapVariant(ProductSaleElements $pse, string $locale): array
    {
        $product = $pse->getProduct();
        $product->setLocale($locale);
        $variant = $this->mapPse($pse, $locale);

        return [
            'id' => $variant['id'],
            'ref' => $variant['ref'],
            'productId' => (int) $product->getId(),
            'productTitle' => $product->getTitle() ?? '',
            'url' => $product->getUrl($locale),
            'label' => $variant['label'],
            'price' => $variant['price'],
            'promoPrice' => $variant['promoPrice'],
            'currency' => $this->session()?->getCurrency()->getCode(),
            'inStock' => $variant['inStock'],
            'imageUrl' => $variant['imageUrl'],
        ];
    }

    private function currencyId(): int
    {
        return (int) ($this->session()?->getCurrency()->getId() ?? 1);
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

        $product = $this->mapMember($member, $ctx->locale);
        $product['pses'] = array_map(
            fn (ProductSaleElements $pse): array => $this->mapPse($pse, $ctx->locale),
            $this->pseFacade->getByProduct($productId),
        );

        return $product;
    }

    public function getDefaultCategoryId(int $productId): ?int
    {
        $product = ProductQuery::create()->findPk($productId);
        if ($product === null) {
            return null;
        }

        $categoryId = $product->getDefaultCategoryId();

        return $categoryId > 0 ? $categoryId : null;
    }

    /**
     * @return list<array>
     */
    private function runSearch(string $query, ?int $categoryId, ?int $featureAvId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, string $locale): array
    {
        $filters = [
            'visible' => true,
            'itemsPerPage' => $limit,
            'page' => 1,
            'order[position]' => 'asc',
            'order[ref]' => 'asc',
        ];
        if ($query !== '') {
            $filters['title'] = $query;
        }
        if ($categoryId !== null) {
            $filters['productCategories.category.id'] = $categoryId;
        }
        if ($featureAvId !== null) {
            $filters['featureProducts.featureAv.id'] = $featureAvId;
        }
        if ($promoOnly) {
            $filters['productSaleElements.promo'] = true;
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
            $products[] = $this->mapMember($member, $locale);
        }

        return $products;
    }

    private function mapMember(array $member, string $locale): array
    {
        $productId = (int) ($member['id'] ?? 0);
        $defaultPse = $this->pseFacade->getDefaultPSE($productId);
        $pricing = $defaultPse !== null ? $this->taxedPricing($defaultPse) : null;
        // product_price.promo_price stays filled (often 0) on products that are
        // not discounted: without this guard the assistant announces fake deals.
        $isPromo = $defaultPse !== null && (bool) $defaultPse->getPromo();

        $inStock = false;
        foreach ($member['productSaleElements'] ?? [] as $pse) {
            if (((int) ($pse['quantity'] ?? 0)) > 0) {
                $inStock = true;
                break;
            }
        }

        $categories = [];
        foreach ($member['productCategories'] ?? [] as $productCategory) {
            $title = self::i18nValue($productCategory['category'] ?? [], 'title', $locale);
            if ($title !== '') {
                $categories[] = $title;
            }
        }

        return [
            'id' => $productId,
            'ref' => (string) ($member['ref'] ?? ''),
            'title' => self::i18nValue($member, 'title', $locale),
            'description' => $this->excerpt(self::i18nValue($member, 'description', $locale)),
            'categories' => array_values(array_unique($categories)),
            'price' => $pricing['price'] ?? null,
            'promoPrice' => $isPromo ? ($pricing['promoPrice'] ?? null) : null,
            'currency' => $this->session()?->getCurrency()->getCode(),
            'url' => $member['publicUrl'] ?? null,
            'imageUrl' => $this->thumbnailProvider->urlFor($productId),
            'inStock' => $inStock,
        ];
    }

    /**
     * DataAccessService flattens i18ns onto the request locale; the raw API keeps
     * one entry per locale. Read both shapes so neither caller silently gets ''.
     */
    private static function i18nValue(array $node, string $field, string $locale): string
    {
        $i18ns = $node['i18ns'] ?? [];
        if (!\is_array($i18ns) || $i18ns === []) {
            return '';
        }

        if (\array_key_exists($field, $i18ns)) {
            return (string) ($i18ns[$field] ?? '');
        }

        $translation = $i18ns[$locale] ?? reset($i18ns);

        return \is_array($translation) ? (string) ($translation[$field] ?? '') : '';
    }

    private function excerpt(string $html): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        if (mb_strlen($text) <= self::EXCERPT_LENGTH) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH), " \t\n\r,;:.").'…';
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
            // "Blue" on its own says nothing in a list; "Colors: Blue" does.
            'label' => self::variantLabel($attributes),
            'price' => $pricing['price'],
            'promoPrice' => $pse->getPromo() ? $pricing['promoPrice'] : null,
            'stock' => (float) $pse->getQuantity(),
            'inStock' => $pse->getQuantity() > 0,
            'imageUrl' => $this->thumbnailProvider->urlForVariant((int) $pse->getId(), (int) $pse->getProductId()),
        ];
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function variantLabel(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $parts[] = sprintf('%s: %s', $name, $value);
        }

        return implode(' · ', $parts);
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

    private function session(): ?\Thelia\Core\HttpFoundation\Session\Session
    {
        $session = $this->requestStack->getMainRequest()?->getSession();

        return $session instanceof \Thelia\Core\HttpFoundation\Session\Session ? $session : null;
    }
}
