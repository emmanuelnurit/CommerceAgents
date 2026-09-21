<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\WelcomeCouponScenarioResolver;
use CommerceAgents\Tests\Tool\Shopping\FakeCouponGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeOrderGateway;
use PHPUnit\Framework\TestCase;

class WelcomeCouponScenarioResolverTest extends TestCase
{
    public function testIgnoresOtherSignals(): void
    {
        $resolver = new WelcomeCouponScenarioResolver(new FakeCouponGateway(), new FakeOrderGateway());

        $this->assertNull($resolver->resolve(new ProactiveSignal('add_to_cart'), new ToolContext()));
    }

    public function testNoMessageWithoutAWelcomeFlavouredCoupon(): void
    {
        $gateway = new FakeCouponGateway(applicable: [
            ['code' => 'SUMMER10', 'title' => 'Soldes été', 'shortDescription' => '', 'discountLabel' => '-10%'],
        ]);
        $resolver = new WelcomeCouponScenarioResolver($gateway, new FakeOrderGateway());

        $this->assertNull($resolver->resolve(new ProactiveSignal('first_visit'), new ToolContext()));
    }

    public function testWelcomeMessageForAnonymousVisitor(): void
    {
        $gateway = new FakeCouponGateway(applicable: [
            ['code' => 'BIENVENUE10', 'title' => 'Remise de bienvenue', 'shortDescription' => 'Sur votre 1ère commande', 'discountLabel' => '-10%'],
        ]);
        $resolver = new WelcomeCouponScenarioResolver($gateway, new FakeOrderGateway());

        $message = $resolver->resolve(new ProactiveSignal('first_visit'), new ToolContext(customerId: null));

        $this->assertNotNull($message);
        $this->assertSame('BIENVENUE10', $message->couponCode);
        $this->assertStringContainsString('BIENVENUE10', $message->message);
    }

    public function testWelcomeMessageEvenWhenCouponsMinimumAmountIsNotYetMet(): void
    {
        // MYO-502 AC-a: first_visit fires on an empty cart, so a welcome
        // coupon with its own minimum-amount condition (e.g. WELCOME10 >=
        // 25€) never appears in findApplicableCoupons() at that point —
        // the resolver must still find it via findConfiguredCoupons().
        $gateway = new FakeCouponGateway(
            applicable: [],
            configured: [
                ['code' => 'WELCOME10', 'title' => 'Bienvenue', 'shortDescription' => 'Dès 25€ d\'achat', 'discountLabel' => '-10%'],
            ],
        );
        $resolver = new WelcomeCouponScenarioResolver($gateway, new FakeOrderGateway());

        $message = $resolver->resolve(new ProactiveSignal('first_visit'), new ToolContext(customerId: null));

        $this->assertNotNull($message);
        $this->assertSame('WELCOME10', $message->couponCode);
        $this->assertSame('Dès 25€ d\'achat', $message->conditionLabel);
    }

    public function testNoMessageForCustomerWhoAlreadyOrdered(): void
    {
        $gateway = new FakeCouponGateway(applicable: [
            ['code' => 'BIENVENUE10', 'title' => 'Remise de bienvenue', 'shortDescription' => '', 'discountLabel' => '-10%'],
        ]);
        $orderGateway = new FakeOrderGateway([['ref' => 'ORD-1']]);
        $resolver = new WelcomeCouponScenarioResolver($gateway, $orderGateway);

        $this->assertNull($resolver->resolve(new ProactiveSignal('first_visit'), new ToolContext(customerId: 42)));
    }

    public function testWelcomeMessageForLoggedInCustomerWithNoOrderYet(): void
    {
        $gateway = new FakeCouponGateway(applicable: [
            ['code' => 'WELCOME', 'title' => 'Welcome offer', 'shortDescription' => '', 'discountLabel' => '-5€'],
        ]);
        $resolver = new WelcomeCouponScenarioResolver($gateway, new FakeOrderGateway());

        $message = $resolver->resolve(new ProactiveSignal('first_visit'), new ToolContext(customerId: 42));

        $this->assertNotNull($message);
        $this->assertSame('WELCOME', $message->couponCode);
    }
}
