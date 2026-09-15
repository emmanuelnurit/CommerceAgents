<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Proactive;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Proactive\CrossSellPromoScenarioResolver;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use PHPUnit\Framework\TestCase;

final class FakeCrossSellCatalogGateway implements CatalogGatewayInterface
{
    public ?int $lastSearchedCategoryId = null;
    public ?int $lastPromoOnly = null;

    /**
     * @param list<array>     $searchResults
     * @param array<int, int> $defaultCategoryIds product id => category id
     */
    public function __construct(
        private readonly array $searchResults = [],
        private readonly array $defaultCategoryIds = [],
    ) {
    }

    public function searchProducts(?string $query, ?int $categoryId, ?int $featureAvId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        $this->lastSearchedCategoryId = $categoryId;
        $this->lastPromoOnly = $promoOnly ? 1 : 0;

        return ['products' => $this->searchResults, 'matchedCategory' => null];
    }

    public function searchVariants(?string $query, int $attributeAvId, ?int $categoryId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        return [];
    }

    public function getProductDetails(int $productId, ToolContext $ctx): ?array
    {
        return null;
    }

    public function getDefaultCategoryId(int $productId): ?int
    {
        return $this->defaultCategoryIds[$productId] ?? null;
    }
}

final class CrossSellPromoScenarioResolverTest extends TestCase
{
    private function product(int $id, float $price, ?float $promoPrice): array
    {
        return [
            'id' => $id,
            'title' => 'Fauteuil Oslo '.$id,
            'price' => $price,
            'promoPrice' => $promoPrice,
            'currency' => 'EUR',
            'url' => 'https://shop.example/product-'.$id,
            'imageUrl' => 'https://shop.example/img-'.$id.'.jpg',
            'inStock' => true,
        ];
    }

    public function testIgnoresASignalOfAnotherType(): void
    {
        $resolver = new CrossSellPromoScenarioResolver(new FakeCrossSellCatalogGateway());

        $result = $resolver->resolve(new ProactiveSignal('cart_idle'), new ToolContext());

        $this->assertNull($result);
    }

    public function testNoMessageWithoutAnyCategoryHint(): void
    {
        $resolver = new CrossSellPromoScenarioResolver(new FakeCrossSellCatalogGateway());

        $result = $resolver->resolve(new ProactiveSignal(CrossSellPromoScenarioResolver::SIGNAL_TYPE, []), new ToolContext());

        $this->assertNull($result);
    }

    public function testSuggestsADiscountedProductFromTheSameCategoryPage(): void
    {
        $gateway = new FakeCrossSellCatalogGateway([$this->product(42, 100.0, 80.0)]);
        $resolver = new CrossSellPromoScenarioResolver($gateway);

        $result = $resolver->resolve(
            new ProactiveSignal(CrossSellPromoScenarioResolver::SIGNAL_TYPE, ['category_id' => 7]),
            new ToolContext(),
        );

        $this->assertNotNull($result);
        $this->assertSame(7, $gateway->lastSearchedCategoryId);
        $this->assertSame(1, $gateway->lastPromoOnly);
        $this->assertSame(42, $result->productId);
        $this->assertSame(100.0, $result->productPrice);
        $this->assertSame(80.0, $result->productPromoPrice);
        $this->assertStringContainsString('-20%', $result->message);
    }

    public function testResolvesTheCategoryFromTheViewedProductWhenNoneIsGiven(): void
    {
        $gateway = new FakeCrossSellCatalogGateway(
            searchResults: [$this->product(99, 50.0, 40.0)],
            defaultCategoryIds: [42 => 7],
        );
        $resolver = new CrossSellPromoScenarioResolver($gateway);

        $result = $resolver->resolve(
            new ProactiveSignal(CrossSellPromoScenarioResolver::SIGNAL_TYPE, ['product_id' => 42]),
            new ToolContext(),
        );

        $this->assertSame(7, $gateway->lastSearchedCategoryId);
        $this->assertSame(99, $result?->productId);
    }

    public function testNeverSuggestsTheProductCurrentlyBeingViewed(): void
    {
        $gateway = new FakeCrossSellCatalogGateway([$this->product(42, 100.0, 80.0)]);
        $resolver = new CrossSellPromoScenarioResolver($gateway);

        $result = $resolver->resolve(
            new ProactiveSignal(CrossSellPromoScenarioResolver::SIGNAL_TYPE, ['product_id' => 42, 'category_id' => 7]),
            new ToolContext(),
        );

        $this->assertNull($result);
    }

    public function testNoMessageWhenNothingIsActuallyDiscounted(): void
    {
        $gateway = new FakeCrossSellCatalogGateway([$this->product(42, 100.0, null)]);
        $resolver = new CrossSellPromoScenarioResolver($gateway);

        $result = $resolver->resolve(
            new ProactiveSignal(CrossSellPromoScenarioResolver::SIGNAL_TYPE, ['category_id' => 7]),
            new ToolContext(),
        );

        $this->assertNull($result);
    }
}
