<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CouponGatewayInterface;
use CommerceAgents\Tool\Shopping\SuggestApplicableCouponsTool;
use PHPUnit\Framework\TestCase;

class FakeCouponGateway implements CouponGatewayInterface
{
    public function __construct(
        private readonly array $applicable = [],
        private readonly array $ladder = [],
        private readonly ?array $configured = null,
    ) {
    }

    public function findApplicableCoupons(ToolContext $ctx): array
    {
        return $this->applicable;
    }

    public function findAmountTierLadder(ToolContext $ctx): array
    {
        return $this->ladder;
    }

    public function findConfiguredCoupons(ToolContext $ctx): array
    {
        return $this->configured ?? $this->applicable;
    }
}

class SuggestApplicableCouponsToolTest extends TestCase
{
    public function testReturnsOnlyWhatTheGatewaySays(): void
    {
        $coupons = [
            ['code' => 'WELCOME10', 'title' => 'Bienvenue', 'shortDescription' => '', 'discountLabel' => '-10%'],
        ];
        $tool = new SuggestApplicableCouponsTool(new FakeCouponGateway($coupons));

        $result = $tool->execute([], new ToolContext());

        $this->assertSame($coupons, $result['coupons']);
    }

    public function testReturnsEmptyListWhenNothingIsEligible(): void
    {
        $tool = new SuggestApplicableCouponsTool(new FakeCouponGateway());

        $this->assertSame([], $tool->execute([], new ToolContext())['coupons']);
    }

    public function testDeniedForAdmin(): void
    {
        $tool = new SuggestApplicableCouponsTool(new FakeCouponGateway());

        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: true)));
        $this->assertTrue($tool->isAllowed(new ToolContext(isAdmin: false)));
    }
}
