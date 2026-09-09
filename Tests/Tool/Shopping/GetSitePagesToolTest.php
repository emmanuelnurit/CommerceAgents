<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\SitePagesGatewayInterface;
use CommerceAgents\Tool\Shopping\GetSitePagesTool;
use PHPUnit\Framework\TestCase;

class FakeSitePagesGateway implements SitePagesGatewayInterface
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

class GetSitePagesToolTest extends TestCase
{
    public function testDelegatesWithContextLocale(): void
    {
        $gateway = new FakeSitePagesGateway([['title' => 'Livraison', 'url' => 'https://shop/livraison', 'type' => 'content']]);
        $tool = new GetSitePagesTool($gateway);

        $result = $tool->execute(['query' => 'livraison'], new ToolContext(locale: 'fr_FR'));

        $this->assertSame(['query' => 'livraison', 'locale' => 'fr_FR'], $gateway->lastCall);
        $this->assertSame(1, $result['count']);
        $this->assertSame('content', $result['pages'][0]['type']);
    }

    public function testQueryIsOptional(): void
    {
        $gateway = new FakeSitePagesGateway();
        (new GetSitePagesTool($gateway))->execute([], new ToolContext());

        $this->assertNull($gateway->lastCall['query']);
    }

    public function testFrontOnly(): void
    {
        $tool = new GetSitePagesTool(new FakeSitePagesGateway());

        $this->assertTrue($tool->isAllowed(new ToolContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
    }
}
