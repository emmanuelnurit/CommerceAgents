<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\OptionGatewayInterface;
use CommerceAgents\Tool\Shopping\SearchProductsTool;
use PHPUnit\Framework\TestCase;

class FakeCatalogGateway implements CatalogGatewayInterface
{
    public array $lastSearch = [];
    public array $lastVariantSearch = [];

    public function __construct(
        private readonly array $results = [],
        private readonly ?array $matchedCategory = null,
        private readonly array $variants = [],
    ) {
    }

    public function searchProducts(?string $query, ?int $categoryId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        $this->lastSearch = [
            'query' => $query,
            'categoryId' => $categoryId,
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'promoOnly' => $promoOnly,
            'limit' => $limit,
        ];

        return ['products' => $this->results, 'matchedCategory' => $this->matchedCategory];
    }

    public function searchVariants(?string $query, int $attributeAvId, ?int $categoryId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        $this->lastVariantSearch = [
            'query' => $query,
            'attributeAvId' => $attributeAvId,
            'categoryId' => $categoryId,
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'promoOnly' => $promoOnly,
            'limit' => $limit,
        ];

        return $this->variants;
    }

    public function getProductDetails(int $productId, ToolContext $ctx): ?array
    {
        return null;
    }
}

class FakeOptionGateway implements OptionGatewayInterface
{
    public function __construct(private readonly ?array $value = ['id' => 3, 'title' => 'Orange', 'attribute' => 'Couleur', 'variantCount' => 11])
    {
    }

    public function getValues(string $locale, int $limit): array
    {
        return $this->value === null ? [] : [$this->value];
    }

    public function findValueByName(string $term, string $locale): ?array
    {
        return $this->value;
    }
}

class SearchProductsToolTest extends TestCase
{
    public function testSearchDelegatesToGateway(): void
    {
        $gateway = new FakeCatalogGateway([['id' => 1, 'title' => 'Chaise']]);
        $tool = new SearchProductsTool($gateway, new FakeOptionGateway());

        $result = $tool->execute(['query' => 'chaise', 'limit' => 3], new ToolContext());

        $this->assertSame('chaise', $gateway->lastSearch['query']);
        $this->assertSame(3, $gateway->lastSearch['limit']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('Chaise', $result['products'][0]['title']);
    }

    public function testLimitDefaultsAndCaps(): void
    {
        $gateway = new FakeCatalogGateway();
        $tool = new SearchProductsTool($gateway, new FakeOptionGateway());

        $tool->execute(['query' => 'x'], new ToolContext());
        $this->assertSame(5, $gateway->lastSearch['limit']);

        $tool->execute(['query' => 'x', 'limit' => 50], new ToolContext());
        $this->assertSame(10, $gateway->lastSearch['limit']);
    }

    public function testOptionalFiltersArePassed(): void
    {
        $gateway = new FakeCatalogGateway();
        $tool = new SearchProductsTool($gateway, new FakeOptionGateway());

        $tool->execute(['query' => 'chaise', 'category_id' => 4, 'min_price' => 10.5, 'max_price' => 99.0], new ToolContext());

        $this->assertSame(4, $gateway->lastSearch['categoryId']);
        $this->assertSame(10.5, $gateway->lastSearch['minPrice']);
        $this->assertSame(99.0, $gateway->lastSearch['maxPrice']);
    }

    public function testAllowedForFrontDeniedForAdmin(): void
    {
        $tool = new SearchProductsTool(new FakeCatalogGateway(), new FakeOptionGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
    }

    public function testSchemaRequiresNothingSoDealsAndCategoriesCanBeListed(): void
    {
        $schema = (new SearchProductsTool(new FakeCatalogGateway(), new FakeOptionGateway()))->getInputSchema();

        $this->assertSame([], $schema['required']);
        $this->assertSame('string', $schema['properties']['query']['type']);
        $this->assertSame('boolean', $schema['properties']['promo']['type']);
        $this->assertArrayHasKey('category_id', $schema['properties']);
        $this->assertArrayHasKey('min_price', $schema['properties']);
        $this->assertArrayHasKey('max_price', $schema['properties']);
        $this->assertArrayHasKey('limit', $schema['properties']);
    }

    public function testPromoOnlySearchNeedsNoQuery(): void
    {
        $gateway = new FakeCatalogGateway();
        $tool = new SearchProductsTool($gateway, new FakeOptionGateway());

        $tool->execute(['promo' => true], new ToolContext());

        $this->assertNull($gateway->lastSearch['query']);
        $this->assertTrue($gateway->lastSearch['promoOnly']);
    }

    public function testPromoDefaultsToFalse(): void
    {
        $gateway = new FakeCatalogGateway();
        $tool = new SearchProductsTool($gateway, new FakeOptionGateway());

        $tool->execute(['query' => 'stacy'], new ToolContext());

        $this->assertFalse($gateway->lastSearch['promoOnly']);
    }

    public function testCategoryFallbackIsReportedToTheAgent(): void
    {
        $gateway = new FakeCatalogGateway(
            [['id' => 14, 'title' => 'Sally']],
            ['id' => 3, 'title' => 'Chairs', 'url' => 'https://shop.example/chairs.html', 'productCount' => 14],
        );

        $result = (new SearchProductsTool($gateway, new FakeOptionGateway()))->execute(['query' => 'chairs'], new ToolContext());

        $this->assertSame('Chairs', $result['matched_category']['title']);
        $this->assertSame(1, $result['count']);
    }

    public function testNoFallbackIsReportedOnADirectHit(): void
    {
        $result = (new SearchProductsTool(new FakeCatalogGateway([['id' => 3, 'title' => 'Stacy']]), new FakeOptionGateway()))
            ->execute(['query' => 'stacy'], new ToolContext());

        $this->assertNull($result['matched_category']);
    }

    public function testAnOptionSearchReturnsVariantsRatherThanProducts(): void
    {
        $gateway = new FakeCatalogGateway([], null, [
            ['id' => 12, 'ref' => 'PROD003-2', 'productId' => 3, 'productTitle' => 'Stacy', 'label' => 'Couleur: Orange'],
        ]);

        $result = (new SearchProductsTool($gateway, new FakeOptionGateway()))
            ->execute(['option' => 'orange'], new ToolContext());

        $this->assertSame(1, $result['count']);
        $this->assertSame('PROD003-2', $result['variants'][0]['ref']);
        $this->assertSame('Orange', $result['matched_option']['title']);
        $this->assertArrayNotHasKey('products', $result);
        $this->assertSame(3, $gateway->lastVariantSearch['attributeAvId']);
    }

    public function testAnOptionSearchKeeps_theOtherFilters(): void
    {
        $gateway = new FakeCatalogGateway();

        (new SearchProductsTool($gateway, new FakeOptionGateway()))->execute(
            ['option' => 'orange', 'query' => 'stacy', 'category_id' => 5, 'max_price' => 300, 'promo' => true, 'limit' => 4],
            new ToolContext(),
        );

        $this->assertSame('stacy', $gateway->lastVariantSearch['query']);
        $this->assertSame(5, $gateway->lastVariantSearch['categoryId']);
        $this->assertSame(300.0, $gateway->lastVariantSearch['maxPrice']);
        $this->assertTrue($gateway->lastVariantSearch['promoOnly']);
        $this->assertSame(4, $gateway->lastVariantSearch['limit']);
    }

    public function testAnUnknownOptionSaysSoRatherThanSearchingEverything(): void
    {
        $gateway = new FakeCatalogGateway();

        $result = (new SearchProductsTool($gateway, new FakeOptionGateway(null)))
            ->execute(['option' => 'fluo'], new ToolContext());

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['variants']);
        $this->assertSame('fluo', $result['unknown_option']);
        $this->assertSame([], $gateway->lastVariantSearch);
    }

    public function testABlankOptionFallsBackToAProductSearch(): void
    {
        $gateway = new FakeCatalogGateway([['id' => 3, 'title' => 'Stacy']]);

        $result = (new SearchProductsTool($gateway, new FakeOptionGateway()))
            ->execute(['option' => '   ', 'query' => 'stacy'], new ToolContext());

        $this->assertArrayHasKey('products', $result);
        $this->assertSame('stacy', $gateway->lastSearch['query']);
    }
}
