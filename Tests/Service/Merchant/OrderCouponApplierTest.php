<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use CommerceAgents\Service\Merchant\OrderCouponApplier;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Model\OrderCouponQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

final class OrderCouponApplierTest extends IntegrationTestCase
{
    private FixtureFactory $factory;
    private OrderCouponApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->applier = new OrderCouponApplier();
    }

    private function change(int $orderId, array $after, array $before = []): StagedChangeData
    {
        return new StagedChangeData(
            id: 1,
            targetType: 'order_coupon',
            targetId: $orderId,
            payloadBefore: $before,
            payloadAfter: $after,
            status: StagedChangeData::STATUS_PENDING,
        );
    }

    public function testApplyCreatesTheOrderCouponRow(): void
    {
        $order = $this->factory->order();
        $coupon = $this->factory->coupon(['code' => 'PROMO10', 'effects' => ['amount' => 10.0]]);

        $this->applier->apply($this->change($order->getId(), [
            'code' => $coupon->getCode(),
            'type' => $coupon->getType(),
            'amount' => 10.0,
            'serializedEffects' => $coupon->getSerializedEffects(),
            'title' => $coupon->getTitle(),
            'shortDescription' => $coupon->getShortDescription(),
            'description' => $coupon->getDescription(),
            'expirationDate' => $coupon->getExpirationDate()->format(\DateTimeInterface::ATOM),
            'isCumulative' => false,
            'isRemovingPostage' => false,
            'isAvailableOnSpecialOffers' => false,
            'serializedConditions' => $coupon->getSerializedConditions(),
            'perCustomerUsageCount' => false,
        ]));

        $orderCoupon = OrderCouponQuery::create()->filterByOrderId($order->getId())->findOne();
        $this->assertNotNull($orderCoupon);
        $this->assertSame('PROMO10', $orderCoupon->getCode());
        $this->assertEqualsWithDelta(10.0, (float) $orderCoupon->getAmount(), 0.001);
    }

    public function testApplyRefusesWhenCouponAlreadyAppliedSinceProposal(): void
    {
        $order = $this->factory->order();
        $coupon = $this->factory->coupon(['code' => 'PROMO10']);
        // Simulates another channel applying the same coupon after the proposal was made.
        $this->factory->orderCoupon($order, $coupon);

        $this->expectException(\RuntimeException::class);

        try {
            $this->applier->apply($this->change($order->getId(), ['code' => 'PROMO10', 'type' => $coupon->getType(), 'title' => 'x', 'shortDescription' => '', 'description' => '']));
        } finally {
            $this->assertSame(1, OrderCouponQuery::create()->filterByOrderId($order->getId())->count(), 'No duplicate order_coupon row must be created');
        }
    }

    public function testApplyRefusesWhenOrderNoLongerExists(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->applier->apply($this->change(999999999, ['code' => 'PROMO10', 'type' => 't', 'title' => 'x', 'shortDescription' => '', 'description' => '']));
    }
}
