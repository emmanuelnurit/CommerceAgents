<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\OpenAdminPageTool;
use CommerceAgents\Tool\Shopping\Gateway\SiteUrlValidatorInterface;
use PHPUnit\Framework\TestCase;

class FakeAdminSiteUrlValidator implements SiteUrlValidatorInterface
{
    public function isSiteUrl(string $url): bool
    {
        return str_starts_with($url, 'https://shop.example/') || str_starts_with($url, '/');
    }
}

class OpenAdminPageToolTest extends TestCase
{
    private function tool(): OpenAdminPageTool
    {
        return new OpenAdminPageTool(new FakeAdminSiteUrlValidator());
    }

    private function admin(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testAdminOnly(): void
    {
        $this->assertTrue($this->tool()->isAllowed($this->admin()));
        $this->assertFalse($this->tool()->isAllowed(new ToolContext(isAdmin: false)));
    }

    public function testReturnsNavigationForAdminUrl(): void
    {
        $result = $this->tool()->execute(['url' => 'https://shop.example/admin/orders'], $this->admin());

        $this->assertSame('https://shop.example/admin/orders', $result['navigation']['url']);
        $this->assertStringContainsString('being redirected', $result['message']);
    }

    public function testRelativeAdminPathIsAccepted(): void
    {
        $result = $this->tool()->execute(['url' => '/admin/customers'], $this->admin());

        $this->assertSame('/admin/customers', $result['navigation']['url']);
    }

    public function testFrontUrlOfTheSameSiteIsRefused(): void
    {
        $result = $this->tool()->execute(['url' => 'https://shop.example/horatio.html'], $this->admin());

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('navigation', $result);
    }

    public function testExternalUrlIsRefused(): void
    {
        $result = $this->tool()->execute(['url' => 'https://evil.example/admin/orders'], $this->admin());

        $this->assertArrayHasKey('error', $result);
    }

    public function testAdminPrefixMustBeAWholeSegment(): void
    {
        $result = $this->tool()->execute(['url' => '/administrator-tools'], $this->admin());

        $this->assertArrayHasKey('error', $result);
    }
}
