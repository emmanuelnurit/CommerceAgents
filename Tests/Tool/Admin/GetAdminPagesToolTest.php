<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\AdminPagesGatewayInterface;
use CommerceAgents\Tool\Admin\GetAdminPagesTool;
use PHPUnit\Framework\TestCase;

class FakeAdminPagesGateway implements AdminPagesGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $pages = [])
    {
    }

    public function getPages(?string $query, string $locale): array
    {
        $this->lastCall = ['query' => $query, 'locale' => $locale];

        return $this->pages;
    }
}

class GetAdminPagesToolTest extends TestCase
{
    public function testAdminOnly(): void
    {
        $tool = new GetAdminPagesTool(new FakeAdminPagesGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: true, adminId: 1)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false, customerId: 3)));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true, adminId: null)));
    }

    public function testDelegatesWithContextLocaleAndOptionalQuery(): void
    {
        $gateway = new FakeAdminPagesGateway([['title' => 'Commandes', 'url' => 'https://shop/admin/orders', 'key' => 'orders']]);
        $tool = new GetAdminPagesTool($gateway);

        $result = $tool->execute(['query' => 'commandes'], new ToolContext(isAdmin: true, adminId: 1, locale: 'fr_FR'));

        $this->assertSame(['query' => 'commandes', 'locale' => 'fr_FR'], $gateway->lastCall);
        $this->assertSame(1, $result['count']);
        $this->assertSame('orders', $result['pages'][0]['key']);

        $tool->execute([], new ToolContext(isAdmin: true, adminId: 1));
        $this->assertNull($gateway->lastCall['query']);
    }
}
