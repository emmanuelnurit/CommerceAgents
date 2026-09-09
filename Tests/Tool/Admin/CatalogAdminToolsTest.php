<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\CatalogAdminGatewayInterface;
use CommerceAgents\Tool\Admin\GetInventoryTool;
use CommerceAgents\Tool\Admin\GetListingsTool;
use CommerceAgents\Tool\Admin\GetPricingTool;
use PHPUnit\Framework\TestCase;

class FakeCatalogAdminGateway implements CatalogAdminGatewayInterface
{
    public array $lastCall = [];

    public function __construct(
        private readonly array $listings = [],
        private readonly array $inventory = [],
        private readonly ?array $pricing = null,
    ) {
    }

    public function getListings(?string $search, int $limit, int $offset, ToolContext $ctx): array
    {
        $this->lastCall = ['search' => $search, 'limit' => $limit, 'offset' => $offset];

        return $this->listings;
    }

    public function getInventory(?float $lowStockThreshold, int $limit, ToolContext $ctx): array
    {
        $this->lastCall = ['lowStockThreshold' => $lowStockThreshold, 'limit' => $limit];

        return $this->inventory;
    }

    public function getPricing(int $productId, ToolContext $ctx): ?array
    {
        $this->lastCall = ['productId' => $productId];

        return $this->pricing;
    }
}

class CatalogAdminToolsTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testAllToolsGateOnAdmin(): void
    {
        $gateway = new FakeCatalogAdminGateway();
        $front = new ToolContext(isAdmin: false);

        foreach ([new GetListingsTool($gateway), new GetInventoryTool($gateway), new GetPricingTool($gateway)] as $tool) {
            $this->assertTrue($tool->isAllowed($this->adminContext()), $tool->getName());
            $this->assertFalse($tool->isAllowed($front), $tool->getName());
            $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)), $tool->getName());
        }
    }

    public function testListingsDefaultsAndCaps(): void
    {
        $gateway = new FakeCatalogAdminGateway(listings: [['id' => 1]]);
        $tool = new GetListingsTool($gateway);

        $result = $tool->execute([], $this->adminContext());
        $this->assertSame(10, $gateway->lastCall['limit']);
        $this->assertSame(0, $gateway->lastCall['offset']);
        $this->assertNull($gateway->lastCall['search']);
        $this->assertSame(1, $result['count']);

        $tool->execute(['limit' => 500, 'search' => 'chaise', 'offset' => 20], $this->adminContext());
        $this->assertSame(50, $gateway->lastCall['limit']);
        $this->assertSame('chaise', $gateway->lastCall['search']);
        $this->assertSame(20, $gateway->lastCall['offset']);
    }

    public function testInventoryThresholdAndLimit(): void
    {
        $gateway = new FakeCatalogAdminGateway(inventory: [['pseId' => 4, 'quantity' => 0.0]]);
        $tool = new GetInventoryTool($gateway);

        $result = $tool->execute([], $this->adminContext());
        $this->assertNull($gateway->lastCall['lowStockThreshold']);
        $this->assertSame(20, $gateway->lastCall['limit']);
        $this->assertSame(0.0, $result['inventory'][0]['quantity']);

        $tool->execute(['low_stock_threshold' => 5, 'limit' => 500], $this->adminContext());
        $this->assertSame(5.0, $gateway->lastCall['lowStockThreshold']);
        $this->assertSame(100, $gateway->lastCall['limit']);
    }

    public function testPricingRequiresProductIdAndHandlesUnknown(): void
    {
        $tool = new GetPricingTool(new FakeCatalogAdminGateway(pricing: null));

        $result = $tool->execute(['product_id' => 999], $this->adminContext());
        $this->assertSame('Product not found', $result['error']);

        $schema = $tool->getInputSchema();
        $this->assertSame(['product_id'], $schema['required']);
    }

    public function testPricingReturnsGatewayPayload(): void
    {
        $gateway = new FakeCatalogAdminGateway(pricing: ['productId' => 3, 'pses' => []]);
        $tool = new GetPricingTool($gateway);

        $result = $tool->execute(['product_id' => 3], $this->adminContext());

        $this->assertSame(3, $gateway->lastCall['productId']);
        $this->assertSame(3, $result['pricing']['productId']);
    }
}
