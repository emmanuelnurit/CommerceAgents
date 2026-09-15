<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\CustomerOrdersGatewayInterface;
use CommerceAgents\Tool\Admin\GetCustomerOrdersTool;
use PHPUnit\Framework\TestCase;

class FakeCustomerOrdersGateway implements CustomerOrdersGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $orders = [])
    {
    }

    public function getOrdersForCustomer(int $customerId, int $limit, ToolContext $ctx): array
    {
        $this->lastCall = ['customerId' => $customerId, 'limit' => $limit];

        return $this->orders;
    }
}

class GetCustomerOrdersToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdminWithId(): void
    {
        $tool = new GetCustomerOrdersTool(new FakeCustomerOrdersGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false, customerId: 42)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    public function testLimitDefaultsAndBounds(): void
    {
        $gateway = new FakeCustomerOrdersGateway();
        $tool = new GetCustomerOrdersTool($gateway);

        $tool->execute(['customer_id' => 42], $this->adminContext());
        $this->assertSame(['customerId' => 42, 'limit' => 10], $gateway->lastCall);

        $tool->execute(['customer_id' => 42, 'limit' => 1000], $this->adminContext());
        $this->assertSame(50, $gateway->lastCall['limit']);

        $tool->execute(['customer_id' => 42, 'limit' => 0], $this->adminContext());
        $this->assertSame(1, $gateway->lastCall['limit']);
    }

    public function testInvalidCustomerIdReturnsError(): void
    {
        $result = (new GetCustomerOrdersTool(new FakeCustomerOrdersGateway()))->execute(['customer_id' => -1], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
    }

    public function testDelegatesToGateway(): void
    {
        $gateway = new FakeCustomerOrdersGateway([['ref' => 'ORD-1', 'totalAmount' => 42.0]]);
        $tool = new GetCustomerOrdersTool($gateway);

        $result = $tool->execute(['customer_id' => 42], $this->adminContext());

        $this->assertSame('ORD-1', $result['orders'][0]['ref']);
    }
}
