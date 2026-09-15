<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\GetProductDetailsTool;
use PHPUnit\Framework\TestCase;

class FakeDetailsCatalogGateway implements CatalogGatewayInterface
{
    public ?int $lastProductId = null;

    public function __construct(private readonly ?array $details = null)
    {
    }

    public function searchProducts(?string $query, ?int $categoryId, ?int $featureAvId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        return ['products' => [], 'matchedCategory' => null];
    }

    public function searchVariants(?string $query, int $attributeAvId, ?int $categoryId, ?float $minPrice, ?float $maxPrice, bool $promoOnly, int $limit, ToolContext $ctx): array
    {
        return [];
    }

    public function getProductDetails(int $productId, ToolContext $ctx): ?array
    {
        $this->lastProductId = $productId;

        return $this->details;
    }

    public function getDefaultCategoryId(int $productId): ?int
    {
        return null;
    }
}

class GetProductDetailsToolTest extends TestCase
{
    public function testReturnsProductFromGateway(): void
    {
        $gateway = new FakeDetailsCatalogGateway(['id' => 7, 'title' => 'Chaise', 'pses' => []]);
        $tool = new GetProductDetailsTool($gateway);

        $result = $tool->execute(['product_id' => 7], new ToolContext());

        $this->assertSame(7, $gateway->lastProductId);
        $this->assertSame('Chaise', $result['product']['title']);
    }

    public function testUnknownProductReturnsErrorNotException(): void
    {
        $tool = new GetProductDetailsTool(new FakeDetailsCatalogGateway(null));

        $result = $tool->execute(['product_id' => 999], new ToolContext());

        $this->assertSame('Product not found', $result['error']);
    }

    public function testSchemaRequiresProductId(): void
    {
        $schema = (new GetProductDetailsTool(new FakeDetailsCatalogGateway()))->getInputSchema();

        $this->assertSame(['product_id'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['product_id']['type']);
    }

    public function testAllowedForFrontOnly(): void
    {
        $tool = new GetProductDetailsTool(new FakeDetailsCatalogGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
    }
}
