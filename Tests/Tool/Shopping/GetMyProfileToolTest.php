<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CustomerGatewayInterface;
use CommerceAgents\Tool\Shopping\GetMyProfileTool;
use PHPUnit\Framework\TestCase;

class FakeCustomerGateway implements CustomerGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly ?array $profile = null)
    {
    }

    public function getProfile(int $customerId, string $locale): ?array
    {
        $this->lastCall = ['customerId' => $customerId, 'locale' => $locale];

        return $this->profile;
    }
}

class GetMyProfileToolTest extends TestCase
{
    public function testDeniedWhenNotLoggedInOrAdmin(): void
    {
        $tool = new GetMyProfileTool(new FakeCustomerGateway());

        $this->assertFalse($tool->isAllowed(new ToolContext(customerId: null)));
        $this->assertTrue($tool->isAllowed(new ToolContext(customerId: 42)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, customerId: 42)));
    }

    public function testCustomerIdComesFromContextNeverFromArguments(): void
    {
        $gateway = new FakeCustomerGateway(['firstName' => 'Jean', 'email' => 'jean@example.com']);
        $tool = new GetMyProfileTool($gateway);

        $result = $tool->execute(['customer_id' => 999], new ToolContext(customerId: 42, locale: 'fr_FR'));

        $this->assertSame(['customerId' => 42, 'locale' => 'fr_FR'], $gateway->lastCall);
        $this->assertSame('Jean', $result['profile']['firstName']);
    }

    public function testExecuteWithoutCustomerReturnsError(): void
    {
        $result = (new GetMyProfileTool(new FakeCustomerGateway()))->execute([], new ToolContext(customerId: null));

        $this->assertSame('Customer must be logged in to see their profile', $result['error']);
    }

    public function testUnknownProfileReturnsError(): void
    {
        $result = (new GetMyProfileTool(new FakeCustomerGateway(null)))->execute([], new ToolContext(customerId: 42));

        $this->assertArrayHasKey('error', $result);
    }
}
