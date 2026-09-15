<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Model\OrderCoupon;
use Thelia\Model\OrderCouponQuery;
use Thelia\Model\OrderQuery;

final readonly class OrderCouponApplier implements ChangeApplierInterface
{
    public function getTargetType(): string
    {
        return 'order_coupon';
    }

    public function apply(StagedChangeData $change): void
    {
        $order = OrderQuery::create()->findPk($change->targetId);
        if ($order === null) {
            throw new \RuntimeException(\sprintf('Order %d no longer exists; refusing to apply a stale proposal', $change->targetId));
        }

        $this->assertNotDiverged($change);

        $after = $change->payloadAfter;

        (new OrderCoupon())
            ->setOrderId($change->targetId)
            ->setCode((string) $after['code'])
            ->setType((string) $after['type'])
            ->setSerializedEffects((string) ($after['serializedEffects'] ?? '[]'))
            ->setAmount((string) ($after['amount'] ?? 0))
            ->setTitle((string) $after['title'])
            ->setShortDescription((string) $after['shortDescription'])
            ->setDescription((string) $after['description'])
            ->setExpirationDate($after['expirationDate'] ?? null)
            ->setIsCumulative($after['isCumulative'] ?? false)
            ->setIsRemovingPostage($after['isRemovingPostage'] ?? false)
            ->setIsAvailableOnSpecialOffers($after['isAvailableOnSpecialOffers'] ?? false)
            ->setSerializedConditions((string) ($after['serializedConditions'] ?? '[]'))
            ->setPerCustomerUsageCount($after['perCustomerUsageCount'] ?? false)
            ->save();
    }

    /**
     * TOCTOU guard (MYO-284 M5 pattern): the coupon might have been applied
     * to this order by another channel between the proposal and its
     * approval. Refuse rather than create a silent duplicate.
     *
     * @throws \RuntimeException when the coupon is already applied
     */
    private function assertNotDiverged(StagedChangeData $change): void
    {
        $code = $change->payloadAfter['code'] ?? null;
        if ($code === null) {
            return;
        }

        $alreadyApplied = OrderCouponQuery::create()
            ->filterByOrderId($change->targetId)
            ->filterByCode((string) $code)
            ->exists();

        if ($alreadyApplied) {
            throw new \RuntimeException(\sprintf('Coupon "%s" has already been applied to order %d since this proposal was made; refusing to apply a stale proposal', $code, $change->targetId));
        }
    }
}
