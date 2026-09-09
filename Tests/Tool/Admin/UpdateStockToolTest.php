<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\UpdateStockTool;
use PHPUnit\Framework\TestCase;

class UpdateStockToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdmin(): void
    {
        $tool = new UpdateStockTool(new FakeStagingGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
    }

    public function testStagesStockUpdateAndAnnouncesApproval(): void
    {
        $gateway = new FakeStagingGateway(['changeId' => 43, 'status' => 'pending']);
        $tool = new UpdateStockTool($gateway);

        $result = $tool->execute(['pse_id' => 12, 'new_quantity' => 50], $this->adminContext());

        $this->assertSame(['stageStockUpdate', 12, 50.0], $gateway->lastCall);
        $this->assertSame(43, $result['staged_change']['changeId']);
        $this->assertStringContainsString('requires human approval', $result['message']);
    }

    public function testNegativeQuantityIsRefusedWithoutStaging(): void
    {
        $gateway = new FakeStagingGateway();
        $result = (new UpdateStockTool($gateway))->execute(['pse_id' => 12, 'new_quantity' => -1], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
    }

    public function testGatewayErrorIsPassedThrough(): void
    {
        $gateway = new FakeStagingGateway(['error' => 'Variant not found']);
        $result = (new UpdateStockTool($gateway))->execute(['pse_id' => 999, 'new_quantity' => 5], $this->adminContext());

        $this->assertSame('Variant not found', $result['error']);
    }

    public function testSchema(): void
    {
        $schema = (new UpdateStockTool(new FakeStagingGateway()))->getInputSchema();

        $this->assertSame(['pse_id', 'new_quantity'], $schema['required']);
    }
}
