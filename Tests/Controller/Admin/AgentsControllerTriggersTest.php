<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentTriggerQuery;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\AgentTriggerType;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * MYO-344: a trigger checkbox saved from the real wizard form must actually
 * be picked up by the run pipeline (AgentRunQueue), not just persisted with
 * a plausible-looking row. Before the fix, every trigger but "Planification"
 * was stored as `type: 'event'` with a TriggerCatalog display code as
 * `event_name` -- a shape neither the cron-scan queries
 * (enqueueDueAbandonedCartRuns/DueLowStockRuns, which filter on the
 * ABANDONED_CART/LOW_STOCK type) nor the real event dispatch
 * (enqueueForEvent(), matched against the real TheliaEvents constant) ever
 * recognized. These tests go through the actual save route and the actual
 * AgentRunQueue methods, exactly the two ends of the pipeline the ticket
 * found disconnected.
 */
final class AgentsControllerTriggersTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $factory = new FixtureFactory($this->getPropelConnection());
        $admin = $factory->admin();
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        parent::tearDown();
    }

    public function testCartAbandonedTriggerIsPersistedAsABusinessCronTriggerAndPickedUpByTheDetectionQuery(): void
    {
        $agent = $this->createAgentDefinition();
        $this->saveAgent($agent, [
            'trigger_cart_abandoned' => '1',
            'trigger_cart_abandoned_hours' => '6',
        ]);

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($agent->getId())->findOne();
        self::assertSame(AgentTriggerType::ABANDONED_CART, $trigger->getType());
        self::assertNull($trigger->getEventName());
        self::assertNotNull($trigger->getNextRunAt(), 'a detection trigger with no cron of its own is never picked up by dueTriggers()');
        self::assertSame(['delay_hours' => 6], json_decode($trigger->getConditions(), true));

        $factory = $this->createFixtureFactory();
        $customer = $factory->customer($factory->customerTitle());
        $cart = $factory->cart($customer);
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $factory->cartItem($cart, $product);
        $now = new \DateTimeImmutable('+2 hours');
        $cart->setUpdatedAt(\DateTime::createFromImmutable($now->modify('-7 hours')))->save($this->getPropelConnection());

        $runs = $this->getService(AgentRunQueue::class)->enqueueDueAbandonedCartRuns($now);

        $cartIds = array_map(static fn ($run) => json_decode($run->getContext(), true)['cart_id'], $runs);
        self::assertContains($cart->getId(), $cartIds, 'a wizard-created cart_abandoned trigger must be seen by enqueueDueAbandonedCartRuns()');
    }

    public function testLowStockTriggerIsPersistedAsABusinessCronTriggerAndPickedUpByTheDetectionQuery(): void
    {
        $agent = $this->createAgentDefinition();
        $this->saveAgent($agent, [
            'trigger_low_stock' => '1',
            'trigger_low_stock_threshold' => '3',
        ]);

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($agent->getId())->findOne();
        self::assertSame(AgentTriggerType::LOW_STOCK, $trigger->getType());
        self::assertNull($trigger->getEventName());
        self::assertNotNull($trigger->getNextRunAt());
        self::assertSame(['threshold' => 3], json_decode($trigger->getConditions(), true));

        $factory = $this->createFixtureFactory();
        $lowStockProduct = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['baseQuantity' => 1]);

        $now = new \DateTimeImmutable('+2 hours');
        $runs = $this->getService(AgentRunQueue::class)->enqueueDueLowStockRuns($now);

        $pseIds = array_map(static fn ($run) => json_decode($run->getContext(), true)['product_sale_elements_id'], $runs);
        self::assertContains(
            $lowStockProduct->getProductSaleElementss()->getFirst()->getId(),
            $pseIds,
            'a wizard-created low_stock trigger must be seen by enqueueDueLowStockRuns()',
        );
    }

    public function testNewOrderTriggerIsPersistedAsARealThelaOrderPayEventAndPickedUpOnDispatch(): void
    {
        $agent = $this->createAgentDefinition();
        $this->saveAgent($agent, ['trigger_new_order' => '1']);

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($agent->getId())->findOne();
        self::assertSame(AgentTriggerType::EVENT, $trigger->getType());
        self::assertSame(TheliaEvents::ORDER_PAY, $trigger->getEventName(), 'must be the real Thelia event name, not the TriggerCatalog display code');

        $runs = $this->getService(AgentRunQueue::class)->enqueueForEvent(TheliaEvents::ORDER_PAY, 'test:order:pay:'.$agent->getId(), ['amount' => 42.0]);

        self::assertCount(1, $runs);
        self::assertSame($agent->getId(), $runs[0]->getAgentDefinitionId());
    }

    public function testNewCustomerTriggerIsPersistedAsARealTheliaCustomerCreateAccountEventAndPickedUpOnDispatch(): void
    {
        $agent = $this->createAgentDefinition();
        $this->saveAgent($agent, ['trigger_new_customer' => '1']);

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($agent->getId())->findOne();
        self::assertSame(AgentTriggerType::EVENT, $trigger->getType());
        self::assertSame(TheliaEvents::CUSTOMER_CREATEACCOUNT, $trigger->getEventName());

        $runs = $this->getService(AgentRunQueue::class)->enqueueForEvent(TheliaEvents::CUSTOMER_CREATEACCOUNT, 'test:customer:created:'.$agent->getId());

        self::assertCount(1, $runs);
        self::assertSame($agent->getId(), $runs[0]->getAgentDefinitionId());
    }

    public function testOrderStatusChangeTriggerIsPersistedAsARealTheliaEventAndRespectsTheSelectedStatuses(): void
    {
        $agent = $this->createAgentDefinition();
        $this->saveAgent($agent, [
            'trigger_order_status_change' => '1',
            'trigger_order_status_ids' => ['3'],
        ]);

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($agent->getId())->findOne();
        self::assertSame(AgentTriggerType::EVENT, $trigger->getType());
        self::assertSame(TheliaEvents::ORDER_UPDATE_STATUS, $trigger->getEventName());
        self::assertSame(['target_statuses' => [3]], json_decode($trigger->getConditions(), true));

        $matching = $this->getService(AgentRunQueue::class)->enqueueForEvent(
            TheliaEvents::ORDER_UPDATE_STATUS,
            'test:order:status:3:'.$agent->getId(),
            ['status_id' => 3],
        );
        self::assertCount(1, $matching, 'the selected status must trigger a run');

        $nonMatching = $this->getService(AgentRunQueue::class)->enqueueForEvent(
            TheliaEvents::ORDER_UPDATE_STATUS,
            'test:order:status:7:'.$agent->getId(),
            ['status_id' => 7],
        );
        self::assertSame([], $nonMatching, 'a status not selected in the wizard must never trigger a run');
    }

    /**
     * @param array<string, mixed> $triggerFields
     */
    private function saveAgent(AgentDefinition $agent, array $triggerFields): void
    {
        $this->client->request('POST', '/admin/module/CommerceAgents/agents/save', [
            '_token' => $this->csrfToken($agent->getId()),
            'agent_id' => (string) $agent->getId(),
            'title' => 'Test agent',
            'role_prompt' => 'Watch shop events.',
            'enabled' => '1',
        ] + $triggerFields);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }

    private function createAgentDefinition(): AgentDefinition
    {
        $definition = new AgentDefinition();
        $definition
            ->setCode('agent-'.uniqid())
            ->setTitle('Test agent')
            ->setRolePrompt('Watch shop events.')
            ->setEnabled(1)
            ->save($this->getPropelConnection());

        return $definition;
    }

    private function csrfToken(int $agentId): string
    {
        $this->client->request('GET', \sprintf('/admin/module/CommerceAgents/agents/%d/edit', $agentId));
        $crawler = $this->client->getCrawler();

        return (string) $crawler->filter('#agent-form input[name="_token"]')->attr('value');
    }
}
