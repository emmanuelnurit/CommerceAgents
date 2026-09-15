<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\EventListener;

use CommerceAgents\EventListener\AgentTriggerSubscriber;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\Run\AbandonedCartFinder;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\AgentTriggerType;
use CommerceAgents\Service\Run\LowStockFinder;
use CommerceAgents\Tests\Service\Locale\FakeSiteDefaultLocaleProvider;
use Psr\Log\NullLogger;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateMinimalEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\OrderStatus;
use Thelia\Model\OrderStatusQuery;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * The whitelist subscriber (plan MYO-226 §3.3 point 3). Acceptance criteria
 * covered here: a paid order queues exactly one run (dedup), conditions gate
 * a status-change run, and the queued run is never executed synchronously —
 * no LLM call reaches the HTTP request cycle.
 */
class AgentTriggerSubscriberTest extends ActionIntegrationTestCase
{
    private AgentTriggerSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $queue = new AgentRunQueue(
            new NullLogger(),
            new AbandonedCartFinder(),
            new LowStockFinder(),
            new AssistantLocaleResolver(new FakeSiteDefaultLocaleProvider('en_US')),
        );
        $this->subscriber = new AgentTriggerSubscriber($queue);
    }

    public function testItIsWiredAsAKernelEventSubscriberForTheWhitelist(): void
    {
        foreach (AgentTriggerType::WHITELISTED_EVENTS as $eventName) {
            $listeners = $this->dispatcher->getListeners($eventName);
            $found = array_filter($listeners, static fn ($listener) => \is_array($listener) && $listener[0] instanceof AgentTriggerSubscriber);

            self::assertNotEmpty($found, \sprintf('AgentTriggerSubscriber must listen to "%s"', $eventName));
        }
    }

    public function testItAlsoListensToTheFrontOfficeRegistrationEventForTheSameBusinessTrigger(): void
    {
        // Not part of WHITELISTED_EVENTS on purpose: it's the same "new
        // customer" business trigger as CUSTOMER_CREATEACCOUNT, observed via
        // its other technical path (MYO-362), not a new whitelist entry.
        $listeners = $this->dispatcher->getListeners(TheliaEvents::CREATE_CUSTOMER_MINIMAL);
        $found = array_filter($listeners, static fn ($listener) => \is_array($listener) && $listener[0] instanceof AgentTriggerSubscriber);

        self::assertNotEmpty($found, 'AgentTriggerSubscriber must listen to CREATE_CUSTOMER_MINIMAL (real front-office signup)');
    }

    public function testOrderPayQueuesARunOnceForDuplicateDispatch(): void
    {
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::EVENT, eventName: TheliaEvents::ORDER_PAY);

        $order = $this->factory->order();
        $event = new OrderEvent($order);

        $this->subscriber->onOrderPay($event);
        $this->subscriber->onOrderPay($event);

        $run = AgentRunQuery::create()->filterByDedupKey(\sprintf('order:%d:paid', $order->getId()))->findOne();

        self::assertNotNull($run);
        self::assertSame(1, AgentRunQuery::create()->filterByAgentDefinitionId($agent->getId())->count());
        self::assertSame(
            AgentRunQueue::STATUS_QUEUED,
            $run->getStatus(),
            'the subscriber must never execute the agent inline: the run stays queued',
        );
    }

    public function testOrderUpdateStatusRespectsTargetStatusesCondition(): void
    {
        $agent = $this->createAgentDefinition();
        $processing = OrderStatusQuery::create()->findOneByCode(OrderStatus::CODE_PROCESSING);
        $this->createTrigger(
            $agent,
            AgentTriggerType::EVENT,
            eventName: TheliaEvents::ORDER_UPDATE_STATUS,
            conditions: json_encode(['target_statuses' => [$processing->getId()]], \JSON_THROW_ON_ERROR),
        );

        $order = $this->factory->order();

        $notMatching = new OrderEvent($order);
        $notMatching->setStatus($processing->getId() + 1000);
        $this->subscriber->onOrderUpdateStatus($notMatching);

        self::assertSame(0, AgentRunQuery::create()->filterByAgentDefinitionId($agent->getId())->count());

        $matching = new OrderEvent($order);
        $matching->setStatus($processing->getId());
        $this->subscriber->onOrderUpdateStatus($matching);

        self::assertSame(1, AgentRunQuery::create()->filterByAgentDefinitionId($agent->getId())->count());
    }

    public function testCustomerCreateAccountQueuesARun(): void
    {
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::EVENT, eventName: TheliaEvents::CUSTOMER_CREATEACCOUNT);

        $customer = $this->factory->customer($this->factory->customerTitle());
        $event = (new CustomerCreateOrUpdateEvent())->setCustomer($customer);

        $this->subscriber->onCustomerCreateAccount($event);

        self::assertSame(
            1,
            AgentRunQuery::create()->filterByDedupKey(\sprintf('customer:%d:created', $customer->getId()))->count(),
        );
    }

    public function testCustomerCreateMinimalQueuesARun(): void
    {
        // MYO-362: real front-office signup (/customer/register, flexy)
        // dispatches CREATE_CUSTOMER_MINIMAL, never CUSTOMER_CREATEACCOUNT.
        // The trigger is still stored with the canonical event name.
        $agent = $this->createAgentDefinition();
        $this->createTrigger($agent, AgentTriggerType::EVENT, eventName: TheliaEvents::CUSTOMER_CREATEACCOUNT);

        $customer = $this->factory->customer($this->factory->customerTitle());
        $event = (new CustomerCreateOrUpdateMinimalEvent())->setCustomer($customer);

        $this->subscriber->onCustomerCreateMinimal($event);

        self::assertSame(
            1,
            AgentRunQuery::create()->filterByDedupKey(\sprintf('customer:%d:created', $customer->getId()))->count(),
        );
    }

    public function testDisabledAgentNeverGetsARunQueued(): void
    {
        $agent = $this->createAgentDefinition(enabled: false);
        $this->createTrigger($agent, AgentTriggerType::EVENT, eventName: TheliaEvents::ORDER_PAY);

        $order = $this->factory->order();
        $this->subscriber->onOrderPay(new OrderEvent($order));

        self::assertSame(0, AgentRunQuery::create()->filterByAgentDefinitionId($agent->getId())->count());
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
        ?string $conditions = null,
        bool $enabled = true,
    ): AgentTrigger {
        $trigger = new AgentTrigger();
        $trigger
            ->setAgentDefinitionId($agent->getId())
            ->setType($type)
            ->setEventName($eventName)
            ->setConditions($conditions)
            ->setEnabled($enabled ? 1 : 0)
            ->save($this->getPropelConnection());

        return $trigger;
    }
}
