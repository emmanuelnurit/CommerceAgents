<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use CommerceAgents\Service\Merchant\PsePriceApplier;
use CommerceAgents\Service\Merchant\PseUpdateEventBuilder;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-284 M5: a staged price change is approved from a payloadBefore snapshot
 * taken when the agent proposed it. If another channel (BO catalog screen,
 * import, another agent) changed the price in the meantime, applying the
 * proposed delta blindly would silently overwrite that other change (TOCTOU).
 */
final class PsePriceApplierTest extends IntegrationTestCase
{
    private FixtureFactory $factory;
    private PsePriceApplier $applier;
    private ProductSaleElements $pse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->applier = new PsePriceApplier(new PseUpdateEventBuilder(), $this->getService(EventDispatcherInterface::class));

        $currency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        self::assertNotNull($currency, 'Test database must have a default currency configured');

        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $product = $this->factory->product($category, $taxRule, $currency, ['basePrice' => 20.0]);

        $this->pse = \Thelia\Model\ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->filterByIsDefault(true)
            ->findOne();
    }

    public function testApplyUpdatesPriceWhenCurrentStateMatchesPayloadBefore(): void
    {
        $change = new StagedChangeData(
            id: 1,
            targetType: 'pse_price',
            targetId: $this->pse->getId(),
            payloadBefore: ['price' => 20.0, 'promoPrice' => 20.0, 'promo' => false],
            payloadAfter: ['price' => 15.0],
            status: StagedChangeData::STATUS_PENDING,
        );

        $this->applier->apply($change);

        $price = ProductPriceQuery::create()->filterByProductSaleElementsId($this->pse->getId())->findOne();
        self::assertEqualsWithDelta(15.0, (float) $price->getPrice(), 0.001);
    }

    public function testApplyRefusesAStaleProposalWhenPriceHasDivergedSinceItWasMade(): void
    {
        // Simulates another channel changing the price after the agent
        // proposed its own change from a now-outdated snapshot.
        $change = new StagedChangeData(
            id: 2,
            targetType: 'pse_price',
            targetId: $this->pse->getId(),
            payloadBefore: ['price' => 30.0, 'promoPrice' => 30.0, 'promo' => false],
            payloadAfter: ['price' => 15.0],
            status: StagedChangeData::STATUS_PENDING,
        );

        $this->expectException(\RuntimeException::class);

        try {
            $this->applier->apply($change);
        } finally {
            $price = ProductPriceQuery::create()->filterByProductSaleElementsId($this->pse->getId())->findOne();
            self::assertEqualsWithDelta(20.0, (float) $price->getPrice(), 0.001, 'The stale proposal must not have touched the current price');
        }
    }
}
