<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent;
use Thelia\Core\Event\TheliaEvents;

final readonly class PseStockApplier implements ChangeApplierInterface
{
    /** Tolerance for float quantity comparisons. */
    private const QUANTITY_EPSILON = 0.001;

    public function __construct(
        private PseUpdateEventBuilder $eventBuilder,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getTargetType(): string
    {
        return 'pse_stock';
    }

    public function apply(StagedChangeData $change): void
    {
        $event = $this->eventBuilder->buildForCurrentValues($change->targetId);

        $this->assertNotDiverged($change, $event);

        $event->setQuantity((float) $change->payloadAfter['quantity']);

        $this->eventDispatcher->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);
    }

    /**
     * TOCTOU guard (MYO-284 M5): see PsePriceApplier::assertNotDiverged().
     *
     * @throws \RuntimeException when the current stock no longer matches payloadBefore
     */
    private function assertNotDiverged(StagedChangeData $change, ProductSaleElementUpdateEvent $event): void
    {
        $expectedQuantity = $change->payloadBefore['quantity'] ?? null;

        if ($expectedQuantity !== null && \abs($event->getQuantity() - (float) $expectedQuantity) > self::QUANTITY_EPSILON) {
            throw new \RuntimeException(\sprintf(
                'Variant %d stock has changed since this proposal was made (expected quantity %.3f, found %.3f); refusing to apply a stale proposal',
                $change->targetId,
                (float) $expectedQuantity,
                $event->getQuantity(),
            ));
        }
    }
}
