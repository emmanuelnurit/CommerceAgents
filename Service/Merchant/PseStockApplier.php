<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;

final readonly class PseStockApplier implements ChangeApplierInterface
{
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

        $event->setQuantity((float) $change->payloadAfter['quantity']);

        $this->eventDispatcher->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);
    }
}
