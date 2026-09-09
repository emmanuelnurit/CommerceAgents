<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;

final readonly class PsePriceApplier implements ChangeApplierInterface
{
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

        $event->setPrice((float) $change->payloadAfter['price']);
        if (isset($change->payloadAfter['promoPrice'])) {
            $event->setSalePrice((float) $change->payloadAfter['promoPrice']);
        }
        if (isset($change->payloadAfter['promo'])) {
            $event->setOnsale($change->payloadAfter['promo'] ? 1 : 0);
        }

        $this->eventDispatcher->dispatch($event, TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT);
    }
}
