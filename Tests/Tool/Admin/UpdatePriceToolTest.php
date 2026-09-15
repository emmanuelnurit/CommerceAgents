<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;
use CommerceAgents\Tool\Admin\UpdatePriceTool;
use PHPUnit\Framework\TestCase;

class FakeStagingGateway implements StagingGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $result = ['changeId' => 1, 'status' => 'pending'])
    {
    }

    public function stagePriceUpdate(int $pseId, float $newPrice, ?float $newPromoPrice, ToolContext $ctx): array
    {
        $this->lastCall = ['stagePriceUpdate', $pseId, $newPrice, $newPromoPrice];

        return $this->result;
    }

    public function stageStockUpdate(int $pseId, float $newQuantity, ToolContext $ctx): array
    {
        $this->lastCall = ['stageStockUpdate', $pseId, $newQuantity];

        return $this->result;
    }

    public function stageCouponApplication(int $orderId, string $couponCode, ToolContext $ctx): array
    {
        $this->lastCall = ['stageCouponApplication', $orderId, $couponCode];

        return $this->result;
    }
}

class UpdatePriceToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdmin(): void
    {
        $tool = new UpdatePriceTool(new FakeStagingGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    public function testStagesPriceUpdateAndAnnouncesApproval(): void
    {
        $gateway = new FakeStagingGateway(['changeId' => 42, 'status' => 'pending', 'before' => ['price' => 30.0], 'after' => ['price' => 25.0]]);
        $tool = new UpdatePriceTool($gateway);

        $result = $tool->execute(['pse_id' => 12, 'new_price' => 25.0], $this->adminContext());

        $this->assertSame(['stagePriceUpdate', 12, 25.0, null], $gateway->lastCall);
        $this->assertSame(42, $result['staged_change']['changeId']);
        $this->assertStringContainsString('requires human approval', $result['message']);
    }

    public function testPromoPriceIsForwarded(): void
    {
        $gateway = new FakeStagingGateway();
        (new UpdatePriceTool($gateway))->execute(['pse_id' => 12, 'new_price' => 25.0, 'new_promo_price' => 19.9], $this->adminContext());

        $this->assertSame(['stagePriceUpdate', 12, 25.0, 19.9], $gateway->lastCall);
    }

    public function testNonPositivePriceIsRefusedWithoutStaging(): void
    {
        $gateway = new FakeStagingGateway();
        $result = (new UpdatePriceTool($gateway))->execute(['pse_id' => 12, 'new_price' => 0], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
    }

    public function testGatewayErrorIsPassedThrough(): void
    {
        $gateway = new FakeStagingGateway(['error' => 'Variant not found']);
        $result = (new UpdatePriceTool($gateway))->execute(['pse_id' => 999, 'new_price' => 25.0], $this->adminContext());

        $this->assertSame('Variant not found', $result['error']);
    }

    public function testSchema(): void
    {
        $schema = (new UpdatePriceTool(new FakeStagingGateway()))->getInputSchema();

        $this->assertSame(['pse_id', 'new_price'], $schema['required']);
        $this->assertArrayHasKey('new_promo_price', $schema['properties']);
    }
}
