<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\ApplyCouponTool;
use PHPUnit\Framework\TestCase;

class ApplyCouponToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1, conversationId: 9);
    }

    public function testGateRequiresAdminWithId(): void
    {
        $tool = new ApplyCouponTool(new FakeStagingGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    /**
     * The whole point of item 3 (MYO-286): applying a coupon never writes
     * directly, it only ever creates a StagedChange proposal through the
     * gateway — approval happens later, in the console.
     */
    public function testAppliesThroughStagedChangeNeverDirectly(): void
    {
        $gateway = new FakeStagingGateway(['changeId' => 55, 'status' => 'pending', 'before' => [], 'after' => ['code' => 'PROMO10']]);
        $tool = new ApplyCouponTool($gateway);

        $result = $tool->execute(['order_id' => 12, 'coupon_code' => 'PROMO10'], $this->adminContext());

        $this->assertSame(['stageCouponApplication', 12, 'PROMO10'], $gateway->lastCall);
        $this->assertSame(55, $result['staged_change']['changeId']);
        $this->assertSame('pending', $result['staged_change']['status']);
        $this->assertStringContainsString('requires human approval', $result['message']);
    }

    public function testCouponCodeIsTrimmed(): void
    {
        $gateway = new FakeStagingGateway();
        (new ApplyCouponTool($gateway))->execute(['order_id' => 12, 'coupon_code' => '  PROMO10  '], $this->adminContext());

        $this->assertSame(['stageCouponApplication', 12, 'PROMO10'], $gateway->lastCall);
    }

    public function testInvalidOrderIdIsRefusedWithoutStaging(): void
    {
        $gateway = new FakeStagingGateway();
        $result = (new ApplyCouponTool($gateway))->execute(['order_id' => 0, 'coupon_code' => 'PROMO10'], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
    }

    public function testEmptyCouponCodeIsRefusedWithoutStaging(): void
    {
        $gateway = new FakeStagingGateway();
        $result = (new ApplyCouponTool($gateway))->execute(['order_id' => 12, 'coupon_code' => '  '], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
    }

    public function testGatewayErrorIsPassedThrough(): void
    {
        $gateway = new FakeStagingGateway(['error' => 'Order not found']);
        $result = (new ApplyCouponTool($gateway))->execute(['order_id' => 999, 'coupon_code' => 'PROMO10'], $this->adminContext());

        $this->assertSame('Order not found', $result['error']);
    }

    public function testSchema(): void
    {
        $schema = (new ApplyCouponTool(new FakeStagingGateway()))->getInputSchema();

        $this->assertSame(['order_id', 'coupon_code'], $schema['required']);
    }
}
