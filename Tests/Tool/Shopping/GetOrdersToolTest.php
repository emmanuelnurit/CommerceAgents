<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\OrderGatewayInterface;
use CommerceAgents\Tool\Shopping\GetOrdersTool;
use PHPUnit\Framework\TestCase;

class FakeOrderGateway implements OrderGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $orders = [])
    {
    }

    public function getOrders(int $customerId, int $limit, ToolContext $ctx): array
    {
        $this->lastCall = ['customerId' => $customerId, 'limit' => $limit];

        return $this->orders;
    }
}

class GetOrdersToolTest extends TestCase
{
    public function testDeniedWhenNotLoggedIn(): void
    {
        $tool = new GetOrdersTool(new FakeOrderGateway());

        $this->assertFalse($tool->isAllowed(new ToolContext(customerId: null)));
        $this->assertTrue($tool->isAllowed(new ToolContext(customerId: 42)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, customerId: 42)));
    }

    public function testDeniedWhenOrdersDisabled(): void
    {
        $tool = new GetOrdersTool(new FakeOrderGateway(), ordersEnabled: false);

        $this->assertFalse($tool->isAllowed(new ToolContext(customerId: 42)));
    }

    public function testCustomerIdComesFromContextNeverFromArguments(): void
    {
        $gateway = new FakeOrderGateway([['ref' => 'ORD-1']]);
        $tool = new GetOrdersTool($gateway);

        $result = $tool->execute(['customer_id' => 999], new ToolContext(customerId: 42));

        $this->assertSame(42, $gateway->lastCall['customerId']);
        $this->assertSame('ORD-1', $result['orders'][0]['ref']);
    }

    public function testLimitDefaultsAndCaps(): void
    {
        $gateway = new FakeOrderGateway();
        $tool = new GetOrdersTool($gateway);

        $tool->execute([], new ToolContext(customerId: 42));
        $this->assertSame(5, $gateway->lastCall['limit']);

        $tool->execute(['limit' => 100], new ToolContext(customerId: 42));
        $this->assertSame(20, $gateway->lastCall['limit']);
    }

    public function testExecuteWithoutCustomerReturnsError(): void
    {
        $result = (new GetOrdersTool(new FakeOrderGateway()))->execute([], new ToolContext(customerId: null));

        $this->assertSame('Customer must be logged in to see orders', $result['error']);
    }
}
