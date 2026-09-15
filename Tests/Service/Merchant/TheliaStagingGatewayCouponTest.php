<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Service\Merchant\TheliaStagingGateway;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Model\OrderCouponQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-286 item 3: applying a coupon must only ever produce a StagedChange
 * proposal, never a direct write to order_coupon.
 */
final class TheliaStagingGatewayCouponTest extends IntegrationTestCase
{
    private FixtureFactory $factory;
    private TheliaStagingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->gateway = new TheliaStagingGateway();
    }

    private function context(int $conversationId): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1, conversationId: $conversationId);
    }

    private function conversationId(): int
    {
        $conversation = (new AgentConversation())
            ->setType('merchant')
            ->setSessionRef('test:'.uniqid('', true));
        $conversation->save();

        return $conversation->getId();
    }

    public function testStagesAChangeWithoutTouchingOrderCoupon(): void
    {
        $order = $this->factory->order();
        $this->factory->coupon(['code' => 'PROMO10', 'effects' => ['amount' => 10.0]]);

        $result = $this->gateway->stageCouponApplication($order->getId(), 'PROMO10', $this->context($this->conversationId()));

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(StagedChangeData::STATUS_PENDING, $result['status']);
        $this->assertSame('order_coupon', $result['targetType']);
        $this->assertSame('PROMO10', $result['after']['code']);

        // The whole point: no direct write happened.
        $this->assertSame(0, OrderCouponQuery::create()->filterByOrderId($order->getId())->count());
    }

    public function testUnknownCouponCodeIsRefused(): void
    {
        $order = $this->factory->order();

        $result = $this->gateway->stageCouponApplication($order->getId(), 'DOES-NOT-EXIST', $this->context($this->conversationId()));

        $this->assertArrayHasKey('error', $result);
    }

    public function testUnknownOrderIsRefused(): void
    {
        $this->factory->coupon(['code' => 'PROMO10']);

        $result = $this->gateway->stageCouponApplication(999999999, 'PROMO10', $this->context($this->conversationId()));

        $this->assertArrayHasKey('error', $result);
    }

    public function testExpiredCouponIsRefused(): void
    {
        $order = $this->factory->order();
        $this->factory->coupon(['code' => 'EXPIRED10', 'expirationDate' => new \DateTime('-1 day')]);

        $result = $this->gateway->stageCouponApplication($order->getId(), 'EXPIRED10', $this->context($this->conversationId()));

        $this->assertArrayHasKey('error', $result);
    }

    public function testAlreadyAppliedCouponIsRefused(): void
    {
        $order = $this->factory->order();
        $coupon = $this->factory->coupon(['code' => 'PROMO10']);
        $this->factory->orderCoupon($order, $coupon);

        $result = $this->gateway->stageCouponApplication($order->getId(), 'PROMO10', $this->context($this->conversationId()));

        $this->assertArrayHasKey('error', $result);
    }
}
