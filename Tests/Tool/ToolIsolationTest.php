<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Tests\Tool\Admin\FakeAnalyticsGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeCatalogGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeFeatureGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeOptionGateway;
use CommerceAgents\Tool\Admin\GetAnalyticsTool;
use CommerceAgents\Tool\Shopping\SearchProductsTool;
use PHPUnit\Framework\TestCase;

class ToolIsolationTest extends TestCase
{
    private function registry(): ToolRegistry
    {
        return new ToolRegistry([
            new SearchProductsTool(new FakeCatalogGateway(), new FakeOptionGateway(), new FakeFeatureGateway()),
            new GetAnalyticsTool(new FakeAnalyticsGateway()),
        ]);
    }

    public function testFrontContextNeverSeesAdminTools(): void
    {
        $specs = $this->registry()->getToolSpecs(new ToolContext(isAdmin: false, customerId: 42));

        $names = array_column($specs, 'name');
        $this->assertContains('search_products', $names);
        $this->assertNotContains('get_analytics', $names);
    }

    public function testAdminContextNeverSeesShoppingTools(): void
    {
        $specs = $this->registry()->getToolSpecs(new ToolContext(isAdmin: true, adminId: 1));

        $names = array_column($specs, 'name');
        $this->assertContains('get_analytics', $names);
        $this->assertNotContains('search_products', $names);
    }

    public function testAdminToolExecutionDeniedForFrontContext(): void
    {
        $this->expectException(ToolException::class);
        $this->registry()->execute('get_analytics', [], new ToolContext(isAdmin: false, customerId: 42));
    }
}
