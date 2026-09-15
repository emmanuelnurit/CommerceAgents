<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Tests\Tool\Admin\FakeAnalyticsGateway;
use CommerceAgents\Tests\Tool\Admin\FakeCustomerAdminGateway;
use CommerceAgents\Tests\Tool\Admin\FakeCustomerOrdersGateway;
use CommerceAgents\Tests\Tool\Admin\FakeStagingGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeCatalogGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeFeatureGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeOptionGateway;
use CommerceAgents\Tool\Admin\ApplyCouponTool;
use CommerceAgents\Tool\Admin\GetAnalyticsTool;
use CommerceAgents\Tool\Admin\GetCustomerOrdersTool;
use CommerceAgents\Tool\Admin\GetCustomerProfileTool;
use CommerceAgents\Tool\Admin\UpdatePriceTool;
use CommerceAgents\Tool\Shopping\SearchProductsTool;
use PHPUnit\Framework\TestCase;

/**
 * An agent definition context only ever sees the tools whose capability it
 * was granted (plan MYO-226 §3.4).
 */
class CapabilityFilteringTest extends TestCase
{
    private function registry(): ToolRegistry
    {
        return new ToolRegistry([
            new SearchProductsTool(new FakeCatalogGateway(), new FakeOptionGateway(), new FakeFeatureGateway()),
            new GetAnalyticsTool(new FakeAnalyticsGateway()),
            new UpdatePriceTool(new FakeStagingGateway()),
            new GetCustomerProfileTool(new FakeCustomerAdminGateway()),
            new GetCustomerOrdersTool(new FakeCustomerOrdersGateway()),
            new ApplyCouponTool(new FakeStagingGateway()),
        ]);
    }

    private function agentContext(array $capabilities): ToolContext
    {
        return new ToolContext(
            isAdmin: true,
            agentDefinitionId: 7,
            capabilities: $capabilities,
        );
    }

    public function testAgentOnlySeesToolsOfItsGrantedCapabilities(): void
    {
        $specs = $this->registry()->getToolSpecs($this->agentContext([Capability::ANALYTICS_READ]));

        $this->assertSame(['get_analytics'], array_column($specs, 'name'));
    }

    public function testAgentWithSeveralCapabilitiesSeesTheirUnion(): void
    {
        $specs = $this->registry()->getToolSpecs($this->agentContext([Capability::ANALYTICS_READ, Capability::PRICING_WRITE]));

        $names = array_column($specs, 'name');
        sort($names);
        $this->assertSame(['get_analytics', 'update_price'], $names);
    }

    public function testAgentWithoutCapabilitiesSeesNothing(): void
    {
        $this->assertSame([], $this->registry()->getToolSpecs($this->agentContext([])));
    }

    public function testExecutingANonGrantedToolIsDenied(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('not allowed');

        $this->registry()->execute('update_price', ['pse_id' => 1, 'new_price' => 10.0], $this->agentContext([Capability::ANALYTICS_READ]));
    }

    public function testAgentCapabilitiesIgnoreSessionOnlyRules(): void
    {
        // An autonomous run has no admin session: the capability is the whole
        // rule, so admin tools stay visible without an adminId.
        $specs = $this->registry()->getToolSpecs(new ToolContext(
            isAdmin: true,
            adminId: null,
            agentDefinitionId: 7,
            capabilities: [Capability::ANALYTICS_READ],
        ));

        $this->assertSame(['get_analytics'], array_column($specs, 'name'));
    }

    public function testHistoricalContextsKeepTheirOwnRules(): void
    {
        // capabilities: null = historical chat context, untouched behaviour.
        $frontSpecs = $this->registry()->getToolSpecs(new ToolContext(isAdmin: false, customerId: 42));
        $adminSpecs = $this->registry()->getToolSpecs(new ToolContext(isAdmin: true, adminId: 1));

        $this->assertSame(['search_products'], array_column($frontSpecs, 'name'));
        $names = array_column($adminSpecs, 'name');
        sort($names);
        $this->assertSame(['apply_coupon_to_order', 'get_analytics', 'get_customer_orders', 'get_customer_profile', 'update_price'], $names);
    }

    public function testCustomerProfileReadIsGrantedIndependentlyFromOrdersRead(): void
    {
        // MYO-286: the study explicitly calls for customer.profile.read to be
        // separable from orders.read — an agent can read a profile without
        // being able to read orders, and vice versa.
        $specs = $this->registry()->getToolSpecs($this->agentContext([Capability::CUSTOMER_PROFILE_READ]));
        $this->assertSame(['get_customer_profile'], array_column($specs, 'name'));

        $specs = $this->registry()->getToolSpecs($this->agentContext([Capability::ORDERS_READ]));
        $this->assertSame(['get_customer_orders'], array_column($specs, 'name'));
    }

    public function testApplyCouponRequiresOrdersWriteAndIsDeniedWithoutIt(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('not allowed');

        $this->registry()->execute(
            'apply_coupon_to_order',
            ['order_id' => 1, 'coupon_code' => 'PROMO10'],
            $this->agentContext([Capability::ORDERS_READ, Capability::CUSTOMER_PROFILE_READ]),
        );
    }

    public function testCustomerProfileToolIsDeniedWithoutItsCapability(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('not allowed');

        $this->registry()->execute(
            'get_customer_profile',
            ['customer_id' => 1],
            $this->agentContext([Capability::ORDERS_READ]),
        );
    }
}
