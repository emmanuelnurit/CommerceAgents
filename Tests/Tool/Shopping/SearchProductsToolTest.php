<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\SearchProductsTool;
use PHPUnit\Framework\TestCase;

class FakeCatalogGateway implements CatalogGatewayInterface
{
    public array $lastSearch = [];

    public function __construct(private readonly array $results = [], private readonly ?array $matchedCategory = null)
    {
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

    public function getProductDetails(int $productId, ToolContext $ctx): ?array
    {
        return null;
    }
}

class SearchProductsToolTest extends TestCase
{
    public function testSearchDelegatesToGateway(): void
    {
        $gateway = new FakeCatalogGateway([['id' => 1, 'title' => 'Chaise']]);
        $tool = new SearchProductsTool($gateway);

        $result = $tool->execute(['query' => 'chaise', 'limit' => 3], new ToolContext());

        $this->assertSame('chaise', $gateway->lastSearch['query']);
        $this->assertSame(3, $gateway->lastSearch['limit']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('Chaise', $result['products'][0]['title']);
    }

    public function testLimitDefaultsAndCaps(): void
    {
        $gateway = new FakeCatalogGateway();
        $tool = new SearchProductsTool($gateway);

        $tool->execute(['query' => 'x'], new ToolContext());
        $this->assertSame(5, $gateway->lastSearch['limit']);

        $tool->execute(['query' => 'x', 'limit' => 50], new ToolContext());
        $this->assertSame(10, $gateway->lastSearch['limit']);
    }

    public function testOptionalFiltersArePassed(): void
    {
        $gateway = new FakeCatalogGateway();
        $tool = new SearchProductsTool($gateway);

        $tool->execute(['query' => 'chaise', 'category_id' => 4, 'min_price' => 10.5, 'max_price' => 99.0], new ToolContext());

        $this->assertSame(4, $gateway->lastSearch['categoryId']);
        $this->assertSame(10.5, $gateway->lastSearch['minPrice']);
        $this->assertSame(99.0, $gateway->lastSearch['maxPrice']);
    }

    public function testAllowedForFrontDeniedForAdmin(): void
    {
        $tool = new SearchProductsTool(new FakeCatalogGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
    }

    public function testSchemaRequiresNothingSoDealsAndCategoriesCanBeListed(): void
    {
        $schema = (new SearchProductsTool(new FakeCatalogGateway()))->getInputSchema();

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
        $tool = new SearchProductsTool($gateway);

        $tool->execute(['promo' => true], new ToolContext());

        $this->assertNull($gateway->lastSearch['query']);
        $this->assertTrue($gateway->lastSearch['promoOnly']);
    }

    public function testPromoDefaultsToFalse(): void
    {
        $gateway = new FakeCatalogGateway();
        $tool = new SearchProductsTool($gateway);

        $tool->execute(['query' => 'stacy'], new ToolContext());

        $this->assertFalse($gateway->lastSearch['promoOnly']);
    }

    public function testCategoryFallbackIsReportedToTheAgent(): void
    {
        $gateway = new FakeCatalogGateway(
            [['id' => 14, 'title' => 'Sally']],
            ['id' => 3, 'title' => 'Chairs', 'url' => 'https://shop.example/chairs.html', 'productCount' => 14],
        );

        $result = (new SearchProductsTool($gateway))->execute(['query' => 'chairs'], new ToolContext());

        $this->assertSame('Chairs', $result['matched_category']['title']);
        $this->assertSame(1, $result['count']);
    }

    public function testNoFallbackIsReportedOnADirectHit(): void
    {
        $result = (new SearchProductsTool(new FakeCatalogGateway([['id' => 3, 'title' => 'Stacy']])))
            ->execute(['query' => 'stacy'], new ToolContext());

        $this->assertNull($result['matched_category']);
    }
}
