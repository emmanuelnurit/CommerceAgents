<?php

declare(strict_types=1);

namespace CommerceAgents\EventListener;

use CommerceAgents\Service\Run\AgentRunQueue;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateMinimalEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\Customer;

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
 *
 * "New customer account" has two distinct technical realizations that both
 * mean the same business event: `CUSTOMER_CREATEACCOUNT` (BO admin screen,
 * {@see \Thelia\Action\Customer::create()}) and `CREATE_CUSTOMER_MINIMAL`
 * (real front-office signup, {@see \Thelia\Domain\Customer\Service\CustomerRegistrationService},
 * MYO-362). Both handlers queue against the same canonical
 * `TheliaEvents::CUSTOMER_CREATEACCOUNT` event name, since that is the only
 * value ever written to `agent_trigger.event_name` for this trigger
 * ({@see \CommerceAgents\Service\Run\TriggerCatalogMapping}) — this is not a
 * new whitelist entry, just observing the real path a new customer is
 * created on the live shop in addition to the BO one.
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
            TheliaEvents::CREATE_CUSTOMER_MINIMAL => 'onCustomerCreateMinimal',
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
        $this->enqueueNewCustomerRun($event->getCustomer());
    }

    /**
     * Real front-office registration (`/customer/register`, flexy) — the
     * event 100% of shop traffic actually goes through. Missing this was
     * MYO-362: the "New customer" trigger only ever fired for the rare
     * BO-admin-created-a-client case.
     */
    public function onCustomerCreateMinimal(CustomerCreateOrUpdateMinimalEvent $event): void
    {
        $this->enqueueNewCustomerRun($event->getCustomer());
    }

    private function enqueueNewCustomerRun(?Customer $customer): void
    {
        if ($customer === null) {
            // Defensive only: both Action\Customer handlers always set the
            // customer on the event before this subscriber runs (lower
            // priority than their priority-128 listeners).
            return;
        }

        $this->queue->enqueueForEvent(
            TheliaEvents::CUSTOMER_CREATEACCOUNT,
            \sprintf('customer:%d:created', $customer->getId()),
            [],
            ['customer_id' => $customer->getId()],
        );
    }
}
