<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use CommerceAgents\Service\Merchant\PseStockApplier;
use CommerceAgents\Service\Merchant\PseUpdateEventBuilder;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-284 M5: same TOCTOU concern as PsePriceApplierTest, for stock.
 */
final class PseStockApplierTest extends IntegrationTestCase
{
    private FixtureFactory $factory;
    private PseStockApplier $applier;
    private ProductSaleElements $pse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->applier = new PseStockApplier(new PseUpdateEventBuilder(), $this->getService(EventDispatcherInterface::class));

        $currency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        self::assertNotNull($currency, 'Test database must have a default currency configured');

        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $product = $this->factory->product($category, $taxRule, $currency, ['baseQuantity' => 10]);

        $this->pse = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->filterByIsDefault(true)
            ->findOne();
    }

    public function testApplyUpdatesQuantityWhenCurrentStateMatchesPayloadBefore(): void
    {
        $change = new StagedChangeData(
            id: 1,
            targetType: 'pse_stock',
            targetId: $this->pse->getId(),
            payloadBefore: ['quantity' => 10.0],
            payloadAfter: ['quantity' => 3.0],
            status: StagedChangeData::STATUS_PENDING,
        );

        $this->applier->apply($change);

        $pse = ProductSaleElementsQuery::create()->findPk($this->pse->getId());
        self::assertEqualsWithDelta(3.0, (float) $pse->getQuantity(), 0.001);
    }

    public function testApplyRefusesAStaleProposalWhenStockHasDivergedSinceItWasMade(): void
    {
        // Another channel (e.g. a manual BO correction) moved the stock after
        // the agent computed its own proposal from an outdated snapshot.
        $change = new StagedChangeData(
            id: 2,
            targetType: 'pse_stock',
            targetId: $this->pse->getId(),
            payloadBefore: ['quantity' => 999.0],
            payloadAfter: ['quantity' => 3.0],
            status: StagedChangeData::STATUS_PENDING,
        );

        $this->expectException(\RuntimeException::class);

        try {
            $this->applier->apply($change);
        } finally {
            $pse = ProductSaleElementsQuery::create()->findPk($this->pse->getId());
            self::assertEqualsWithDelta(10.0, (float) $pse->getQuantity(), 0.001, 'The stale proposal must not have touched the current stock');
        }
    }
}
