<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\AnalyticsGatewayInterface;
use CommerceAgents\Tool\Admin\GetAnalyticsTool;
use PHPUnit\Framework\TestCase;

class FakeAnalyticsGateway implements AnalyticsGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $analytics = [])
    {
    }

    public function getSalesAnalytics(int $periodDays, ToolContext $ctx): array
    {
        $this->lastCall = ['periodDays' => $periodDays];

        return $this->analytics;
    }
}

class GetAnalyticsToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdminWithId(): void
    {
        $tool = new GetAnalyticsTool(new FakeAnalyticsGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false, customerId: 42)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    public function testPeriodDefaultsAndBounds(): void
    {
        $gateway = new FakeAnalyticsGateway();
        $tool = new GetAnalyticsTool($gateway);

        $tool->execute([], $this->adminContext());
        $this->assertSame(30, $gateway->lastCall['periodDays']);

        $tool->execute(['period_days' => 1000], $this->adminContext());
        $this->assertSame(365, $gateway->lastCall['periodDays']);

        $tool->execute(['period_days' => 0], $this->adminContext());
        $this->assertSame(1, $gateway->lastCall['periodDays']);
    }

    public function testDelegatesToGateway(): void
    {
        $gateway = new FakeAnalyticsGateway(['revenue' => 1234.5, 'orderCount' => 12]);
        $tool = new GetAnalyticsTool($gateway);

        $result = $tool->execute(['period_days' => 7], $this->adminContext());

        $this->assertSame(7, $gateway->lastCall['periodDays']);
        $this->assertSame(1234.5, $result['analytics']['revenue']);
    }
}
