<?php

declare(strict_types=1);

namespace CommerceAgents\EventListener;

use CommerceAgents\Service\Run\AgentRunQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * The one Symfony subscriber allowed to turn a whitelisted Thelia event into
 * agent work (plan MYO-226 §3.3 point 3). It only ever calls
 * {@see AgentRunQueue}, which does nothing but INSERT a queued `agent_run` —
 * no LLM client, no runner, no capability check reaches this class, so an
 * agent can never execute inside the HTTP request that fired the event.
 *
 * The whitelist is closed on purpose ({@see \CommerceAgents\Service\Run\AgentTriggerType::WHITELISTED_EVENTS}):
 * order paid, order status changed, new customer account. Widening it is an
 * architecture decision, not something to bolt on for one feature.
 */
final readonly class AgentTriggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AgentRunQueue $queue,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::ORDER_PAY => 'onOrderPay',
            TheliaEvents::ORDER_UPDATE_STATUS => 'onOrderUpdateStatus',
            TheliaEvents::CUSTOMER_CREATEACCOUNT => 'onCustomerCreateAccount',
        ];
    }

    public function onOrderPay(OrderEvent $event): void
    {
        $order = $event->getOrder();

        $this->queue->enqueueForEvent(
            TheliaEvents::ORDER_PAY,
            \sprintf('order:%d:paid', $order->getId()),
            ['amount' => $order->getTotalAmount()],
            ['order_id' => $order->getId()],
        );
    }

    public function onOrderUpdateStatus(OrderEvent $event): void
    {
        $order = $event->getOrder();
        $statusId = $event->getStatus();
        if ($statusId === null) {
            return;
        }

        $this->queue->enqueueForEvent(
            TheliaEvents::ORDER_UPDATE_STATUS,
            \sprintf('order:%d:status:%d', $order->getId(), $statusId),
            ['status_id' => $statusId],
            ['order_id' => $order->getId(), 'status_id' => $statusId],
        );
    }

    public function onCustomerCreateAccount(CustomerCreateOrUpdateEvent $event): void
    {
        $customer = $event->getCustomer();

        $this->queue->enqueueForEvent(
            TheliaEvents::CUSTOMER_CREATEACCOUNT,
            \sprintf('customer:%d:created', $customer->getId()),
            [],
            ['customer_id' => $customer->getId()],
        );
    }
}
