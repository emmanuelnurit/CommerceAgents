<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use Thelia\Core\Event\TheliaEvents;

/**
 * The closed set of `agent_trigger.type` values (plan MYO-226 §3.3, wizard
 * MYO-227 §4.3). Each maps to a business-facing label — never a technical
 * event name — in the trigger wizard.
 */
final class AgentTriggerType
{
    /** One of the Thelia whitelist events below, matched by `event_name`. */
    public const EVENT = 'event';

    /** Pure schedule, no detection query ("Planification"). */
    public const CRON = 'cron';

    /** Cron-scheduled detection query, no native Thelia event ("Panier abandonné"). */
    public const ABANDONED_CART = 'abandoned_cart';

    /** Cron-scheduled detection query, no native Thelia event ("Stock bas"). */
    public const LOW_STOCK = 'low_stock';

    /**
     * The Thelia event whitelist a trigger of type EVENT may subscribe to
     * (plan MYO-226 §3.3 point 3). Never extend this ad hoc: any new event
     * must be a deliberate architecture decision, not a side effect of a
     * feature ticket.
     */
    public const WHITELISTED_EVENTS = [
        TheliaEvents::ORDER_PAY,
        TheliaEvents::ORDER_UPDATE_STATUS,
        TheliaEvents::CUSTOMER_CREATEACCOUNT,
    ];
}
