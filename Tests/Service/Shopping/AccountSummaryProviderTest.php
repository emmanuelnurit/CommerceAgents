<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Shopping\AccountSummaryProvider;
use CommerceAgents\Tool\Shopping\Gateway\OrderGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FakeAccountOrderGateway implements OrderGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $orders = [])
    {
    }

    public function getOrders(int $customerId, int $limit, ToolContext $ctx): array
    {
        $this->lastCall = ['customerId' => $customerId, 'limit' => $limit, 'locale' => $ctx->locale];

        return $this->orders;
    }
}

class AccountSummaryProviderTest extends TestCase
{
    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $name): string => '/'.$name,
        );

        return $urlGenerator;
    }

    public function testAnonymousPayloadNeverTouchesTheOrderGateway(): void
    {
        $gateway = new FakeAccountOrderGateway([['ref' => 'ORD-1']]);
        $provider = new AccountSummaryProvider($gateway, $this->urlGenerator());

        $result = $provider->forAnonymous();

        $this->assertSame([], $gateway->lastCall);
        $this->assertFalse($result['loggedIn']);
        $this->assertArrayNotHasKey('orders', $result);
        $this->assertSame('/customer_login', $result['loginUrl']);
        $this->assertSame('/customer_register', $result['registerUrl']);
    }

    public function testCustomerPayloadCarriesUpToThreeRecentOrders(): void
    {
        $orders = [['ref' => 'ORD-1'], ['ref' => 'ORD-2'], ['ref' => 'ORD-3']];
        $gateway = new FakeAccountOrderGateway($orders);
        $provider = new AccountSummaryProvider($gateway, $this->urlGenerator());

        $result = $provider->forCustomer(42, 'fr_FR');

        $this->assertTrue($result['loggedIn']);
        $this->assertSame('/account_index', $result['accountUrl']);
        $this->assertSame('/account_orders', $result['ordersUrl']);
        $this->assertSame($orders, $result['orders']);
        $this->assertSame(['customerId' => 42, 'limit' => 3, 'locale' => 'fr_FR'], $gateway->lastCall);
    }
}
