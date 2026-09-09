<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\SiteUrlValidatorInterface;
use CommerceAgents\Tool\Shopping\OpenPageTool;
use PHPUnit\Framework\TestCase;

class FakeSiteUrlValidator implements SiteUrlValidatorInterface
{
    public function isSiteUrl(string $url): bool
    {
        return str_starts_with($url, 'https://shop.example/') || str_starts_with($url, '/');
    }
}

class OpenPageToolTest extends TestCase
{
    private function tool(): OpenPageTool
    {
        return new OpenPageTool(new FakeSiteUrlValidator());
    }

    public function testReturnsNavigationForSiteUrl(): void
    {
        $result = $this->tool()->execute(['url' => 'https://shop.example/livraison.html'], new ToolContext());

        $this->assertSame('https://shop.example/livraison.html', $result['navigation']['url']);
        $this->assertStringContainsString('being redirected', $result['message']);
    }

    public function testRelativeUrlIsAccepted(): void
    {
        $result = $this->tool()->execute(['url' => '/checkout/cart'], new ToolContext());

        $this->assertSame('/checkout/cart', $result['navigation']['url']);
    }

    public function testExternalUrlIsRefused(): void
    {
        $result = $this->tool()->execute(['url' => 'https://evil.example/phishing'], new ToolContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('navigation', $result);
    }

    public function testFrontOnly(): void
    {
        $this->assertTrue($this->tool()->isAllowed(new ToolContext()));
        $this->assertFalse($this->tool()->isAllowed(new ToolContext(isAdmin: true)));
    }

    public function testSchemaRequiresUrl(): void
    {
        $this->assertSame(['url'], $this->tool()->getInputSchema()['required']);
    }
}
