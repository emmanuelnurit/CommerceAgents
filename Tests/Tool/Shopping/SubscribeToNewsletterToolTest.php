<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\NewsletterOptinService;
use CommerceAgents\Tool\Shopping\SubscribeToNewsletterTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Mailer\MailerFactory;

class SubscribeToNewsletterToolTest extends TestCase
{
    private function service(array $applicableCoupons = []): NewsletterOptinService
    {
        return new NewsletterOptinService(
            $this->createMock(EventDispatcherInterface::class),
            new FakeCouponGateway(applicable: $applicableCoupons),
            $this->createMock(MailerFactory::class),
            new NullLogger(),
        );
    }

    public function testDeniedForAdmin(): void
    {
        $tool = new SubscribeToNewsletterTool($this->service());

        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: false)));
    }

    public function testSubscribesWithValidEmailAndExplicitConsent(): void
    {
        $tool = new SubscribeToNewsletterTool($this->service());

        $result = $tool->execute(['email' => 'jean@example.com', 'consent' => true], new ToolContext());

        $this->assertSame(['subscribed' => true], $result);
    }

    public function testConsentMustBeExactlyTrueNeverATruthyString(): void
    {
        $tool = new SubscribeToNewsletterTool($this->service());

        $result = $tool->execute(['email' => 'jean@example.com', 'consent' => 'true'], new ToolContext());

        $this->assertFalse($result['subscribed']);
    }

    public function testRejectsWhenEmailIsMissing(): void
    {
        $tool = new SubscribeToNewsletterTool($this->service());

        $result = $tool->execute(['consent' => true], new ToolContext());

        $this->assertFalse($result['subscribed']);
    }

    public function testNeverExposesTheCouponCodeToTheCaller(): void
    {
        $tool = new SubscribeToNewsletterTool($this->service([
            ['code' => 'BIENVENUE10', 'title' => 'Remise de bienvenue', 'shortDescription' => '', 'discountLabel' => '-10%'],
        ]));

        $result = $tool->execute(['email' => 'jean@example.com', 'consent' => true], new ToolContext());

        $this->assertArrayNotHasKey('couponCode', $result);
        $this->assertArrayNotHasKey('code', $result);
    }
}
