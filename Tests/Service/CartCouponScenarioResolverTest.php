<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\CartCouponScenarioResolver;
use CommerceAgents\Tests\Tool\Shopping\FakeCartGateway;
use CommerceAgents\Tests\Tool\Shopping\FakeCouponGateway;
use PHPUnit\Framework\TestCase;

class CartCouponScenarioResolverTest extends TestCase
{
    public function testIgnoresOtherSignals(): void
    {
        $resolver = new CartCouponScenarioResolver(new FakeCouponGateway(), new FakeCartGateway());

        $this->assertNull($resolver->resolve(new ProactiveSignal('low_stock'), new ToolContext()));
    }

    public function testNoMessageWhenNoCouponIsEligible(): void
    {
        $resolver = new CartCouponScenarioResolver(new FakeCouponGateway(), new FakeCartGateway());

        $this->assertNull($resolver->resolve(new ProactiveSignal('add_to_cart'), new ToolContext()));
    }

    public function testSimpleCardWhenOneMatchingCouponAndNoLadder(): void
    {
        $gateway = new FakeCouponGateway(
            applicable: [['code' => 'PROMO10', 'title' => '', 'shortDescription' => 'Dès 50€', 'discountLabel' => '-10%']],
        );
        $resolver = new CartCouponScenarioResolver($gateway, new FakeCartGateway());

        $message = $resolver->resolve(new ProactiveSignal('add_to_cart'), new ToolContext());

        $this->assertNotNull($message);
        $this->assertSame('PROMO10', $message->couponCode);
        $this->assertSame('Dès 50€', $message->conditionLabel);
        $this->assertStringContainsString('PROMO10', $message->message);
        $this->assertNull($message->tiers);
    }

    public function testTieredCardWhenALadderHasAnUnreachedTier(): void
    {
        $gateway = new FakeCouponGateway(
            applicable: [['code' => 'PALIER5', 'title' => '', 'shortDescription' => '', 'discountLabel' => '-5%']],
            ladder: [
                ['code' => 'PALIER5', 'title' => '', 'shortDescription' => '', 'discountLabel' => '-5%', 'threshold' => 50.0, 'matching' => true],
                ['code' => 'PALIER10', 'title' => '', 'shortDescription' => '', 'discountLabel' => '-10%', 'threshold' => 100.0, 'matching' => false],
            ],
        );
        $cart = new FakeCartGateway(['items' => [], 'totalTaxedAmount' => 62.0, 'currency' => 'EUR', 'itemCount' => 1]);
        $resolver = new CartCouponScenarioResolver($gateway, $cart);

        $message = $resolver->resolve(new ProactiveSignal('add_to_cart'), new ToolContext());

        $this->assertNotNull($message);
        $this->assertNotNull($message->tiers);
        $this->assertCount(2, $message->tiers);
        $this->assertTrue($message->tiers[0]['reached']);
        $this->assertFalse($message->tiers[1]['reached']);
        $this->assertSame(62.0, $message->progressPercent);
        $this->assertNotNull($message->progressLabel);
    }

    public function testNoTierExtensionWhenLadderIsFullyUnlocked(): void
    {
        $gateway = new FakeCouponGateway(
            applicable: [['code' => 'PALIER10', 'title' => '', 'shortDescription' => '', 'discountLabel' => '-10%']],
            ladder: [
                ['code' => 'PALIER5', 'title' => '', 'shortDescription' => '', 'discountLabel' => '-5%', 'threshold' => 50.0, 'matching' => true],
                ['code' => 'PALIER10', 'title' => '', 'shortDescription' => '', 'discountLabel' => '-10%', 'threshold' => 100.0, 'matching' => true],
            ],
        );
        $resolver = new CartCouponScenarioResolver($gateway, new FakeCartGateway());

        $message = $resolver->resolve(new ProactiveSignal('add_to_cart'), new ToolContext());

        $this->assertNotNull($message);
        $this->assertNull($message->tiers);
    }
}
