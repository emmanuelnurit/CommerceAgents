<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\SalesVelocityGatewayInterface;
use CommerceAgents\Tool\Admin\GetSalesVelocityTool;
use PHPUnit\Framework\TestCase;

class FakeSalesVelocityGateway implements SalesVelocityGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $result = [
        'pseId' => 12,
        'pseRef' => 'BUR-OSLO-01',
        'productId' => 6,
        'productRef' => 'PROD006',
        'quantity' => 4.0,
        'weeks' => 4,
        'unitsSold' => 24.0,
        'velocityPerWeek' => 6.0,
        'windowStart' => '2026-08-24',
        'windowEnd' => '2026-09-21',
    ])
    {
    }

    public function getSalesVelocity(int $pseId, int $weeks, ToolContext $ctx): array
    {
        $this->lastCall = ['getSalesVelocity', $pseId, $weeks];

        return $this->result;
    }
}

class GetSalesVelocityToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdmin(): void
    {
        $tool = new GetSalesVelocityTool(new FakeSalesVelocityGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    public function testWeeksDefaultsToFour(): void
    {
        $gateway = new FakeSalesVelocityGateway();
        $tool = new GetSalesVelocityTool($gateway);

        $result = $tool->execute(['pse_id' => 12], $this->adminContext());

        $this->assertSame(['getSalesVelocity', 12, 4], $gateway->lastCall);
        $this->assertSame(6.0, $result['velocity']['velocityPerWeek']);
    }

    public function testWeeksIsClampedToMax(): void
    {
        $gateway = new FakeSalesVelocityGateway();
        $tool = new GetSalesVelocityTool($gateway);

        $tool->execute(['pse_id' => 12, 'weeks' => 999], $this->adminContext());

        $this->assertSame(['getSalesVelocity', 12, 12], $gateway->lastCall);
    }

    public function testWeeksIsClampedToMin(): void
    {
        $gateway = new FakeSalesVelocityGateway();
        $tool = new GetSalesVelocityTool($gateway);

        $tool->execute(['pse_id' => 12, 'weeks' => 0], $this->adminContext());

        $this->assertSame(['getSalesVelocity', 12, 1], $gateway->lastCall);
    }

    public function testGatewayErrorIsPassedThrough(): void
    {
        $gateway = new FakeSalesVelocityGateway(['error' => 'Variant not found']);
        $result = (new GetSalesVelocityTool($gateway))->execute(['pse_id' => 999], $this->adminContext());

        $this->assertSame('Variant not found', $result['error']);
    }

    public function testSchema(): void
    {
        $schema = (new GetSalesVelocityTool(new FakeSalesVelocityGateway()))->getInputSchema();

        $this->assertSame(['pse_id'], $schema['required']);
    }
}
