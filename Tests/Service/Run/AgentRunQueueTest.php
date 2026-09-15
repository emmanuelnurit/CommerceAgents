<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Service\Run\AbandonedCartFinder;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\AgentTriggerType;
use CommerceAgents\Service\Run\LowStockFinder;
use Psr\Log\NullLogger;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The two business-cron triggers (plan MYO-226 §3.3 point 4) and the event
 * dedup they share with the event trigger. Real fixtures, real Propel
 * queries — this is exactly the "détecté par les requêtes cron (tests avec
 * fixtures)" acceptance criterion of MYO-230.
 */
class AgentRunQueueTest extends IntegrationTestCase
{
    private AgentRunQueue $queue;

    private FixtureFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new AgentRunQueue(new NullLogger(), new AbandonedCartFinder(), new LowStockFinder());
        $this->factory = $this->createFixtureFactory();
    }

    public function testEnqueueForEventDeduplicatesByDedupKey(): void
    {
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::EVENT, eventName: 'action.order.pay');

        $first = $this->queue->enqueueForEvent('action.order.pay', 'order:1234:paid');
        $second = $this->queue->enqueueForEvent('action.order.pay', 'order:1234:paid');

        self::assertCount(1, $first);
        self::assertSame([], $second, 'a second dispatch of the same dedup_key must not queue a duplicate run');
        self::assertSame(
            1,
            AgentRunQuery::create()->filterByDedupKey('order:1234:paid')->count(),
            'exactly one agent_run row for the dedup key',
        );
        self::assertSame(AgentRunQueue::STATUS_QUEUED, $first[0]->getStatus());
    }

    public function testEnqueueForEventSkipsWhenConditionsDoNotMatch(): void
    {
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::EVENT, eventName: 'action.order.updateStatus', conditions: '{"target_statuses": [99]}');

        $runs = $this->queue->enqueueForEvent('action.order.updateStatus', 'order:1:status:5', ['status_id' => 5]);

        self::assertSame([], $runs);
    }

    public function testEnqueueForEventSkipsDisabledAgent(): void
    {
        $agent = $this->createAgentDefinition(enabled: false);
        $this->createTrigger($agent, AgentTriggerType::EVENT, eventName: 'action.createCustomer');

        $runs = $this->queue->enqueueForEvent('action.createCustomer', 'customer:1:created');

        self::assertSame([], $runs);
    }

    public function testEnqueueDueAbandonedCartRunsDetectsOldCartWithoutOrder(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 10:00:00');
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::ABANDONED_CART, conditions: '{"delay_hours": 2}', nextRunAt: $now);

        $customer = $this->factory->customer($this->factory->customerTitle());
        $cart = $this->factory->cart($customer);
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $this->factory->cartItem($cart, $product);
        $cart->setUpdatedAt(\DateTime::createFromImmutable($now->modify('-3 hours')))->save($this->getPropelConnection());

        $runs = $this->queue->enqueueDueAbandonedCartRuns($now);

        self::assertContains($cart->getId(), $this->cartIdsFrom($runs));
    }

    public function testEnqueueDueAbandonedCartRunsSkipsCartConvertedToOrder(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 10:00:00');
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::ABANDONED_CART, conditions: '{"delay_hours": 2}', nextRunAt: $now);

        $order = $this->factory->order();
        $cart = \Thelia\Model\CartQuery::create()->findPk($order->getCartId());
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $this->factory->cartItem($cart, $product);
        $cart->setUpdatedAt(\DateTime::createFromImmutable($now->modify('-3 hours')))->save($this->getPropelConnection());

        $runs = $this->queue->enqueueDueAbandonedCartRuns($now);

        self::assertNotContains($cart->getId(), $this->cartIdsFrom($runs), 'a cart already converted to an order is never abandoned');
    }

    public function testEnqueueDueAbandonedCartRunsSkipsRecentCart(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 10:00:00');
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::ABANDONED_CART, conditions: '{"delay_hours": 24}', nextRunAt: $now);

        $customer = $this->factory->customer($this->factory->customerTitle());
        $cart = $this->factory->cart($customer);
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());
        $this->factory->cartItem($cart, $product);
        $cart->setUpdatedAt(\DateTime::createFromImmutable($now->modify('-1 hour')))->save($this->getPropelConnection());

        $runs = $this->queue->enqueueDueAbandonedCartRuns($now);

        self::assertNotContains($cart->getId(), $this->cartIdsFrom($runs), 'a cart updated inside the delay window is not abandoned yet');
    }

    public function testEnqueueDueLowStockRunsDetectsSaleElementUnderThreshold(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 10:00:00');
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::LOW_STOCK, conditions: '{"threshold": 5}', nextRunAt: $now);

        $lowStockProduct = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['baseQuantity' => 2],
        );
        $wellStockedProduct = $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->factory->currency(),
            ['baseQuantity' => 50],
        );

        $runs = $this->queue->enqueueDueLowStockRuns($now);
        $pseIds = $this->pseIdsFrom($runs);

        self::assertContains($lowStockProduct->getProductSaleElementss()->getFirst()->getId(), $pseIds);
        self::assertNotContains($wellStockedProduct->getProductSaleElementss()->getFirst()->getId(), $pseIds);
    }

    public function testEnqueueDueTriggersAdvanceTheirSchedule(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 10:00:00');
        $agent = $this->createAgentDefinition();
        $trigger = $this->createTrigger($agent, AgentTriggerType::LOW_STOCK, cronExpression: '0 * * * *', conditions: '{"threshold": 5}', nextRunAt: $now);

        $this->queue->enqueueDueLowStockRuns($now);

        $trigger->reload();
        self::assertEquals($now, $trigger->getLastRunAt());
        self::assertGreaterThan($now, $trigger->getNextRunAt());
    }

    /**
     * @param \CommerceAgents\Model\AgentRun[] $runs
     *
     * @return list<int>
     */
    private function cartIdsFrom(array $runs): array
    {
        return array_map(
            static fn ($run) => json_decode($run->getContext(), true, flags: \JSON_THROW_ON_ERROR)['cart_id'],
            $runs,
        );
    }

    /**
     * @param \CommerceAgents\Model\AgentRun[] $runs
     *
     * @return list<int>
     */
    private function pseIdsFrom(array $runs): array
    {
        return array_map(
            static fn ($run) => json_decode($run->getContext(), true, flags: \JSON_THROW_ON_ERROR)['product_sale_elements_id'],
            $runs,
        );
    }

    private function createAgentDefinition(bool $enabled = true): AgentDefinition
    {
        $definition = new AgentDefinition();
        $definition
            ->setCode('agent-'.uniqid())
            ->setTitle('Test agent')
            ->setEnabled($enabled ? 1 : 0)
            ->save($this->getPropelConnection());

        return $definition;
    }

    private function createTrigger(
        AgentDefinition $agent,
        string $type,
        ?string $eventName = null,
        ?string $cronExpression = null,
        ?string $conditions = null,
        bool $enabled = true,
        ?\DateTimeInterface $nextRunAt = null,
    ): AgentTrigger {
        $trigger = new AgentTrigger();
        $trigger
            ->setAgentDefinitionId($agent->getId())
            ->setType($type)
            ->setEventName($eventName)
            ->setCronExpression($cronExpression)
            ->setConditions($conditions)
            ->setEnabled($enabled ? 1 : 0)
            ->setNextRunAt($nextRunAt)
            ->save($this->getPropelConnection());

        return $trigger;
    }
}
