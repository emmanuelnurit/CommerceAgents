<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Service\Run\AgentTriggerType;
use CommerceAgents\Service\Run\TriggerCatalogMapping;
use CommerceAgents\Service\TriggerCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thelia\Core\Event\TheliaEvents;

/**
 * MYO-344: before this mapping existed, AgentsController::parseTriggers()
 * stored `type: 'event'` for the two business-cron triggers (invisible to
 * AgentRunQueue::enqueueDueAbandonedCartRuns/DueLowStockRuns, which filter on
 * AgentTriggerType::ABANDONED_CART/LOW_STOCK) and a TriggerCatalog display
 * code as `event_name` for genuine event triggers (never matching the real
 * TheliaEvents constant AgentTriggerSubscriber dispatches). Only the
 * "Planification" cron trigger worked.
 */
class TriggerCatalogMappingTest extends TestCase
{
    public function testCartAbandonedMapsToTheAbandonedCartTypeNotEvent(): void
    {
        $technical = TriggerCatalogMapping::technicalFor(TriggerCatalog::CART_ABANDONED);

        self::assertSame(AgentTriggerType::ABANDONED_CART, $technical['type']);
        self::assertNull($technical['eventName']);
        self::assertNotNull($technical['cronExpression'], 'the detection query needs its own schedule to ever become "due"');
    }

    public function testLowStockMapsToTheLowStockTypeNotEvent(): void
    {
        $technical = TriggerCatalogMapping::technicalFor(TriggerCatalog::LOW_STOCK);

        self::assertSame(AgentTriggerType::LOW_STOCK, $technical['type']);
        self::assertNull($technical['eventName']);
        self::assertNotNull($technical['cronExpression']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function realEventTriggers(): iterable
    {
        yield 'new order' => [TriggerCatalog::NEW_ORDER, TheliaEvents::ORDER_PAY];
        yield 'order status change' => [TriggerCatalog::ORDER_STATUS_CHANGE, TheliaEvents::ORDER_UPDATE_STATUS];
        yield 'new customer' => [TriggerCatalog::NEW_CUSTOMER, TheliaEvents::CUSTOMER_CREATEACCOUNT];
    }

    #[DataProvider('realEventTriggers')]
    public function testEventTriggersMapToTheRealTheliaEventNameNotTheCatalogCode(string $catalogCode, string $expectedEventName): void
    {
        $technical = TriggerCatalogMapping::technicalFor($catalogCode);

        self::assertSame(AgentTriggerType::EVENT, $technical['type']);
        self::assertSame($expectedEventName, $technical['eventName'], 'AgentTriggerSubscriber/AgentRunQueue::enqueueForEvent() match on this exact value, never the catalog code');
        self::assertNull($technical['cronExpression']);
    }

    #[DataProvider('realEventTriggers')]
    public function testCatalogCodeForIsTheInverseOfTechnicalFor(string $catalogCode, string $expectedEventName): void
    {
        $technical = TriggerCatalogMapping::technicalFor($catalogCode);
        $trigger = (new AgentTrigger())->setType($technical['type'])->setEventName($technical['eventName']);

        self::assertSame($catalogCode, TriggerCatalogMapping::catalogCodeFor($trigger));
    }

    public function testCatalogCodeForRoundTripsTheBusinessCronTriggers(): void
    {
        $abandonedCart = (new AgentTrigger())->setType(AgentTriggerType::ABANDONED_CART);
        $lowStock = (new AgentTrigger())->setType(AgentTriggerType::LOW_STOCK);

        self::assertSame(TriggerCatalog::CART_ABANDONED, TriggerCatalogMapping::catalogCodeFor($abandonedCart));
        self::assertSame(TriggerCatalog::LOW_STOCK, TriggerCatalogMapping::catalogCodeFor($lowStock));
    }

    public function testCatalogCodeForReturnsScheduleForACronTrigger(): void
    {
        $trigger = (new AgentTrigger())->setType(AgentTriggerType::CRON);

        self::assertSame(TriggerCatalog::SCHEDULE, TriggerCatalogMapping::catalogCodeFor($trigger));
    }

    public function testCatalogCodeForReturnsNullForAnUnrecognizedEventName(): void
    {
        $trigger = (new AgentTrigger())->setType(AgentTriggerType::EVENT)->setEventName('action.some.unrelated.event');

        self::assertNull(TriggerCatalogMapping::catalogCodeFor($trigger));
    }
}
