<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Proactive;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Proactive\LowStockScenarioResolver;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use PHPUnit\Framework\TestCase;

final class FakeLowStockCatalogGateway implements CatalogGatewayInterface
{
    public function __construct(private readonly ?array $product = null)
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
        return $this->product;
    }

    public function getDefaultCategoryId(int $productId): ?int
    {
        return null;
    }
}

final class FakeLowStockCartGateway implements CartGatewayInterface
{
    /**
     * @param list<int> $productIdsInCart
     */
    public function __construct(private readonly array $productIdsInCart = [])
    {
    }

    public function addToCart(int $productId, ?int $productSaleElementsId, int $quantity, ToolContext $ctx): array
    {
        return [];
    }

    public function getCart(ToolContext $ctx): array
    {
        return ['items' => array_map(static fn (int $id): array => ['productId' => $id], $this->productIdsInCart)];
    }
}

final class LowStockScenarioResolverTest extends TestCase
{
    private function productWithPses(array $pses): array
    {
        return ['id' => 42, 'title' => 'Fauteuil Oslo', 'url' => 'https://shop.example/oslo', 'imageUrl' => 'https://shop.example/oslo.jpg', 'pses' => $pses];
    }

    public function testIgnoresASignalOfAnotherType(): void
    {
        $resolver = new LowStockScenarioResolver(new FakeLowStockCatalogGateway(), new FakeLowStockCartGateway(), 5);

        $result = $resolver->resolve(new ProactiveSignal('cart_idle'), new ToolContext());

        $this->assertNull($result);
    }

    public function testWarnsWithTheRealStockCountBelowThreshold(): void
    {
        $product = $this->productWithPses([['id' => 1, 'stock' => 3]]);
        $resolver = new LowStockScenarioResolver(new FakeLowStockCatalogGateway($product), new FakeLowStockCartGateway(), 5);

        $result = $resolver->resolve(
            new ProactiveSignal(LowStockScenarioResolver::SIGNAL_TYPE, ['product_id' => 42]),
            new ToolContext(),
        );

        $this->assertNotNull($result);
        $this->assertSame(42, $result->productId);
        $this->assertStringContainsString('3', $result->message);
        $this->assertSame('Plus que 3 en stock', $result->productStockLabel);
    }

    public function testNoMessageWhenStockIsAtOrAboveTheThreshold(): void
    {
        $product = $this->productWithPses([['id' => 1, 'stock' => 5]]);
        $resolver = new LowStockScenarioResolver(new FakeLowStockCatalogGateway($product), new FakeLowStockCartGateway(), 5);

        $result = $resolver->resolve(
            new ProactiveSignal(LowStockScenarioResolver::SIGNAL_TYPE, ['product_id' => 42]),
            new ToolContext(),
        );

        $this->assertNull($result);
    }

    public function testNoMessageWhenTheProductIsAlreadyInTheCart(): void
    {
        $product = $this->productWithPses([['id' => 1, 'stock' => 2]]);
        $resolver = new LowStockScenarioResolver(new FakeLowStockCatalogGateway($product), new FakeLowStockCartGateway([42]), 5);

        $result = $resolver->resolve(
            new ProactiveSignal(LowStockScenarioResolver::SIGNAL_TYPE, ['product_id' => 42]),
            new ToolContext(),
        );

        $this->assertNull($result);
    }

    public function testUsesTheStockOfTheSpecificVariantBeingViewed(): void
    {
        $product = $this->productWithPses([
            ['id' => 1, 'stock' => 20],
            ['id' => 2, 'stock' => 1],
        ]);
        $resolver = new LowStockScenarioResolver(new FakeLowStockCatalogGateway($product), new FakeLowStockCartGateway(), 5);

        $result = $resolver->resolve(
            new ProactiveSignal(LowStockScenarioResolver::SIGNAL_TYPE, ['product_id' => 42, 'pse_id' => 1]),
            new ToolContext(),
        );

        $this->assertNull($result);
    }

    public function testNoMessageWhenTheProductNoLongerExists(): void
    {
        $resolver = new LowStockScenarioResolver(new FakeLowStockCatalogGateway(null), new FakeLowStockCartGateway(), 5);

        $result = $resolver->resolve(
            new ProactiveSignal(LowStockScenarioResolver::SIGNAL_TYPE, ['product_id' => 42]),
            new ToolContext(),
        );

        $this->assertNull($result);
    }
}
