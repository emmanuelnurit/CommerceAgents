<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use Thelia\Core\Event\ProductSaleElement\ProductSaleElementUpdateEvent;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class PseUpdateEventBuilder
{
    /**
     * Builds a PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT event fully populated with
     * the CURRENT values of the PSE.
     *
     * The core listener (Thelia\Action\ProductSaleElement::update, lines
     * 145-164) is not a partial patch: every field carried by the event
     * overwrites the database row unconditionally. Omitting one would wipe it
     * to 0/empty/false. Callers override only the field they intend to change.
     *
     * @throws \RuntimeException when the PSE or its default-currency price row is missing
     */
    public function buildForCurrentValues(int $pseId): ProductSaleElementUpdateEvent
    {
        $pse = ProductSaleElementsQuery::create()->findPk($pseId);
        if ($pse === null) {
            throw new \RuntimeException(sprintf('Variant %d not found', $pseId));
        }

        $defaultCurrency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        if ($defaultCurrency === null) {
            throw new \RuntimeException('No default currency configured');
        }

        $price = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pseId)
            ->filterByCurrencyId($defaultCurrency->getId())
            ->findOne();
        if ($price === null) {
            throw new \RuntimeException(sprintf('No default-currency price row for variant %d', $pseId));
        }

        $product = $pse->getProduct();

        $event = new ProductSaleElementUpdateEvent($product, $pseId);
        $event
            ->setReference((string) $pse->getRef())
            ->setPrice((float) $price->getPrice())
            ->setCurrencyId($defaultCurrency->getId())
            ->setWeight((float) $pse->getWeight())
            ->setQuantity((float) $pse->getQuantity())
            ->setSalePrice((float) $price->getPromoPrice())
            ->setOnsale($pse->getPromo() ? 1 : 0)
            ->setIsnew($pse->getNewness() ? 1 : 0)
            ->setIsdefault((bool) $pse->getIsDefault())
            ->setEanCode((string) $pse->getEanCode())
            ->setTaxRuleId((int) $product->getTaxRuleId())
            ->setFromDefaultCurrency(0);

        return $event;
    }
}
