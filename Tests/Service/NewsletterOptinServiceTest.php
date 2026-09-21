<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\NewsletterOptinService;
use CommerceAgents\Tests\Tool\Shopping\FakeCouponGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Newsletter\NewsletterEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Mailer\MailerFactory;

class NewsletterOptinServiceTest extends TestCase
{
    public function testRejectsWithoutConsent(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $mailer = $this->createMock(MailerFactory::class);
        $mailer->expects($this->never())->method('sendEmailMessage');

        $service = new NewsletterOptinService($dispatcher, new FakeCouponGateway(), $mailer, new NullLogger());

        $result = $service->subscribe('jean@example.com', false, new ToolContext());

        $this->assertFalse($result['subscribed']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testRejectsInvalidEmail(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');
        $mailer = $this->createMock(MailerFactory::class);
        $mailer->expects($this->never())->method('sendEmailMessage');

        $service = new NewsletterOptinService($dispatcher, new FakeCouponGateway(), $mailer, new NullLogger());

        $result = $service->subscribe('not-an-email', true, new ToolContext());

        $this->assertFalse($result['subscribed']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testSubscribesAndSendsTheWelcomeCouponByEmailOnly(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(NewsletterEvent::class), TheliaEvents::NEWSLETTER_SUBSCRIBE)
            ->willReturnArgument(0);

        $mailer = $this->createMock(MailerFactory::class);
        $mailer->expects($this->once())
            ->method('sendEmailMessage')
            ->with(
                NewsletterOptinService::MESSAGE_CODE,
                $this->anything(),
                ['jean@example.com' => 'jean@example.com'],
                $this->callback(static fn (array $params): bool => 'BIENVENUE10' === $params['coupon_code']),
                'fr_FR',
            );

        $couponGateway = new FakeCouponGateway(applicable: [
            ['code' => 'BIENVENUE10', 'title' => 'Remise de bienvenue', 'shortDescription' => '', 'discountLabel' => '-10%'],
        ]);

        $service = new NewsletterOptinService($dispatcher, $couponGateway, $mailer, new NullLogger());

        $result = $service->subscribe('jean@example.com', true, new ToolContext(locale: 'fr_FR'));

        $this->assertSame(['subscribed' => true], $result, 'the coupon code must never be echoed back to the caller');
    }

    public function testSubscribesWithoutAnyMailWhenNoWelcomeCouponIsConfigured(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch');

        $mailer = $this->createMock(MailerFactory::class);
        $mailer->expects($this->never())->method('sendEmailMessage');

        $service = new NewsletterOptinService($dispatcher, new FakeCouponGateway(), $mailer, new NullLogger());

        $result = $service->subscribe('jean@example.com', true, new ToolContext());

        $this->assertTrue($result['subscribed']);
    }
}
