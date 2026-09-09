<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\AddToCartTool;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\GetCartTool;
use PHPUnit\Framework\TestCase;

class FakeCartGateway implements CartGatewayInterface
{
    public array $lastAdd = [];
    public int $getCartCalls = 0;

    public function __construct(private readonly array $cartState = ['items' => [], 'totalTaxedAmount' => 0, 'currency' => 'EUR', 'itemCount' => 0])
    {
    }

    public function addToCart(int $productId, ?int $productSaleElementsId, int $quantity, ToolContext $ctx): array
    {
        $this->lastAdd = [
            'productId' => $productId,
            'productSaleElementsId' => $productSaleElementsId,
            'quantity' => $quantity,
        ];

        return $this->cartState;
    }

    public function getCart(ToolContext $ctx): array
    {
        ++$this->getCartCalls;

        return $this->cartState;
    }
}

class CartToolsTest extends TestCase
{
    public function testAddToCartDelegatesAndReturnsCartState(): void
    {
        $gateway = new FakeCartGateway(['items' => [['productId' => 3]], 'totalTaxedAmount' => 49.9, 'currency' => 'EUR', 'itemCount' => 1]);
        $tool = new AddToCartTool($gateway);

        $result = $tool->execute(['product_id' => 3, 'quantity' => 2], new ToolContext());

        $this->assertSame(3, $gateway->lastAdd['productId']);
        $this->assertSame(2, $gateway->lastAdd['quantity']);
        $this->assertNull($gateway->lastAdd['productSaleElementsId']);
        $this->assertSame(1, $result['cart']['itemCount']);
    }

    public function testQuantityDefaultsToOneAndIsBounded(): void
    {
        $gateway = new FakeCartGateway();
        $tool = new AddToCartTool($gateway);

        $tool->execute(['product_id' => 3], new ToolContext());
        $this->assertSame(1, $gateway->lastAdd['quantity']);

        $tool->execute(['product_id' => 3, 'quantity' => 99], new ToolContext());
        $this->assertSame(10, $gateway->lastAdd['quantity']);

        $tool->execute(['product_id' => 3, 'quantity' => 0], new ToolContext());
        $this->assertSame(1, $gateway->lastAdd['quantity']);
    }

    public function testAddToCartGateRespectsToggle(): void
    {
        $this->assertTrue((new AddToCartTool(new FakeCartGateway()))->isAllowed(new ToolContext()));
        $this->assertFalse((new AddToCartTool(new FakeCartGateway(), cartEnabled: false))->isAllowed(new ToolContext()));
        $this->assertFalse((new AddToCartTool(new FakeCartGateway()))->isAllowed(new ToolContext(isAdmin: true)));
    }

    public function testAddToCartSchemaRequiresProductId(): void
    {
        $schema = (new AddToCartTool(new FakeCartGateway()))->getInputSchema();

        $this->assertSame(['product_id'], $schema['required']);
        $this->assertArrayHasKey('product_sale_elements_id', $schema['properties']);
        $this->assertArrayHasKey('quantity', $schema['properties']);
    }

    public function testGetCartDelegates(): void
    {
        $gateway = new FakeCartGateway(['items' => [], 'totalTaxedAmount' => 0, 'currency' => 'EUR', 'itemCount' => 0]);
        $tool = new GetCartTool($gateway);

        $result = $tool->execute([], new ToolContext());

        $this->assertSame(1, $gateway->getCartCalls);
        $this->assertSame(0, $result['cart']['itemCount']);
        $this->assertTrue($tool->isAllowed(new ToolContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
    }
}
