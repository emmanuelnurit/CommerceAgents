<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\CustomerAdminGatewayInterface;
use CommerceAgents\Tool\Admin\GetCustomerProfileTool;
use PHPUnit\Framework\TestCase;

class FakeCustomerAdminGateway implements CustomerAdminGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly ?array $profile = null)
    {
    }

    public function getCustomerProfile(int $customerId, string $locale): ?array
    {
        $this->lastCall = ['customerId' => $customerId, 'locale' => $locale];

        return $this->profile;
    }
}

class GetCustomerProfileToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1, locale: 'fr_FR');
    }

    public function testGateRequiresAdminWithId(): void
    {
        $tool = new GetCustomerProfileTool(new FakeCustomerAdminGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false, customerId: 42)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    public function testDelegatesToGatewayWithArgumentCustomerId(): void
    {
        $gateway = new FakeCustomerAdminGateway(['customerId' => 42, 'firstName' => 'Jean', 'email' => 'jean@example.com']);
        $tool = new GetCustomerProfileTool($gateway);

        $result = $tool->execute(['customer_id' => 42], $this->adminContext());

        $this->assertSame(['customerId' => 42, 'locale' => 'fr_FR'], $gateway->lastCall);
        $this->assertSame('Jean', $result['profile']['firstName']);
    }

    public function testInvalidCustomerIdReturnsError(): void
    {
        $result = (new GetCustomerProfileTool(new FakeCustomerAdminGateway()))->execute(['customer_id' => 0], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
    }

    public function testUnknownCustomerReturnsError(): void
    {
        $result = (new GetCustomerProfileTool(new FakeCustomerAdminGateway(null)))->execute(['customer_id' => 999], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
    }
}
