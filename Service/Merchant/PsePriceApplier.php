<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent;
use Thelia\Core\Event\TheliaEvents;

final readonly class PsePriceApplier implements ChangeApplierInterface
{
    /** Tolerance for float price comparisons, in currency units. */
    private const PRICE_EPSILON = 0.005;

    public function __construct(
        private PseUpdateEventBuilder $eventBuilder,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getTargetType(): string
    {
        return 'pse_price';
    }

    public function apply(StagedChangeData $change): void
    {
        $event = $this->eventBuilder->buildForCurrentValues($change->targetId);

        $this->assertNotDiverged($change, $event);

        $event->setPrice((float) $change->payloadAfter['price']);
        if (isset($change->payloadAfter['promoPrice'])) {
            $event->setSalePrice((float) $change->payloadAfter['promoPrice']);
        }
        if (isset($change->payloadAfter['promo'])) {
            $event->setOnsale($change->payloadAfter['promo'] ? 1 : 0);
        }

        $this->eventDispatcher->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);
    }

    /**
     * TOCTOU guard (MYO-284 M5): the proposal was computed from a snapshot of
     * the price/promo taken when the agent proposed it. If another channel
     * changed the variant since then, applying the proposed delta blindly
     * would silently overwrite that other change. Refuse instead.
     *
     * @throws \RuntimeException when the current state no longer matches payloadBefore
     */
    private function assertNotDiverged(StagedChangeData $change, ProductSaleElementUpdateEvent $event): void
    {
        $expectedPrice = $change->payloadBefore['price'] ?? null;
        $expectedPromoPrice = $change->payloadBefore['promoPrice'] ?? null;
        $expectedPromo = $change->payloadBefore['promo'] ?? null;

        $diverged = ($expectedPrice !== null && \abs($event->getPrice() - (float) $expectedPrice) > self::PRICE_EPSILON)
            || ($expectedPromoPrice !== null && \abs($event->getSalePrice() - (float) $expectedPromoPrice) > self::PRICE_EPSILON)
            || ($expectedPromo !== null && (bool) $event->getOnsale() !== (bool) $expectedPromo);

        if ($diverged) {
            throw new \RuntimeException(\sprintf(
                'Variant %d has changed since this price proposal was made (expected price %.2f, found %.2f); refusing to apply a stale proposal',
                $change->targetId,
                (float) $expectedPrice,
                $event->getPrice(),
            ));
        }
    }
}
