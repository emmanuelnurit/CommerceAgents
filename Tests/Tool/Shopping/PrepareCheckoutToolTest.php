<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CheckoutUrlProviderInterface;
use CommerceAgents\Tool\Shopping\PrepareCheckoutTool;
use PHPUnit\Framework\TestCase;

class FakeCheckoutUrlProvider implements CheckoutUrlProviderInterface
{
    public function getCheckoutUrl(): string
    {
        return 'https://shop.example/checkout/cart';
    }
}

class PrepareCheckoutToolTest extends TestCase
{
    private function tool(array $cartState, bool $checkoutEnabled = true): PrepareCheckoutTool
    {
        return new PrepareCheckoutTool(
            new FakeCartGateway($cartState),
            new FakeCheckoutUrlProvider(),
            checkoutEnabled: $checkoutEnabled,
        );
    }

    public function testReturnsSummaryAndCheckoutUrl(): void
    {
        $cart = ['items' => [['productId' => 3, 'quantity' => 1]], 'totalTaxedAmount' => 49.9, 'currency' => 'EUR', 'itemCount' => 1];

        $result = $this->tool($cart)->execute([], new ToolContext());

        $this->assertSame($cart, $result['summary']);
        $this->assertSame('https://shop.example/checkout/cart', $result['checkout_url']);
    }

    public function testEmptyCartReturnsError(): void
    {
        $result = $this->tool(['items' => [], 'totalTaxedAmount' => 0, 'currency' => 'EUR', 'itemCount' => 0])
            ->execute([], new ToolContext());

        $this->assertSame('Cart is empty', $result['error']);
        $this->assertArrayNotHasKey('checkout_url', $result);
    }

    public function testGateRespectsToggleAndAdmin(): void
    {
        $cart = ['items' => [], 'totalTaxedAmount' => 0, 'currency' => 'EUR', 'itemCount' => 0];

        $this->assertTrue($this->tool($cart)->isAllowed(new ToolContext()));
        $this->assertFalse($this->tool($cart, checkoutEnabled: false)->isAllowed(new ToolContext()));
        $this->assertFalse($this->tool($cart)->isAllowed(new ToolContext(isAdmin: true)));
    }
}
