<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Service\TriggerCatalog;
use Thelia\Core\Event\TheliaEvents;

/**
 * The single source of truth (MYO-344) between the wizard's business-facing
 * {@see TriggerCatalog} codes and the technical `agent_trigger` columns the
 * run pipeline actually matches against: {@see AgentTriggerType} for
 * `type`, the real {@see TheliaEvents} constant for `event_name` on event
 * triggers (never a TriggerCatalog code), and a fixed hourly cron for the
 * two business-cron detection triggers (`abandoned_cart`, `low_stock`) so
 * {@see AgentRunQueue}'s `next_run_at`-driven scheduling picks them up like
 * any other cron-backed trigger.
 *
 * Before this class, AgentsController::parseTriggers() (write side) and the
 * edit page / run history (read sides) each carried their own guess at this
 * mapping, and the write side's guess was wrong: it stored `type: 'event'`
 * for the two business-cron triggers (so the cron-scan queries never saw
 * them) and the TriggerCatalog code instead of the real Thelia event name
 * for genuine event triggers (so they never matched a dispatched event
 * either). Only "Planification" (a real `cron` trigger) worked.
 */
final class TriggerCatalogMapping
{
    private const DETECTION_CRON = '0 * * * *';

    /**
     * @return array{type: string, eventName: ?string, cronExpression: ?string}
     */
    public static function technicalFor(string $catalogCode): array
    {
        return match ($catalogCode) {
            TriggerCatalog::CART_ABANDONED => ['type' => AgentTriggerType::ABANDONED_CART, 'eventName' => null, 'cronExpression' => self::DETECTION_CRON],
            TriggerCatalog::LOW_STOCK => ['type' => AgentTriggerType::LOW_STOCK, 'eventName' => null, 'cronExpression' => self::DETECTION_CRON],
            TriggerCatalog::NEW_ORDER => ['type' => AgentTriggerType::EVENT, 'eventName' => TheliaEvents::ORDER_PAY, 'cronExpression' => null],
            TriggerCatalog::ORDER_STATUS_CHANGE => ['type' => AgentTriggerType::EVENT, 'eventName' => TheliaEvents::ORDER_UPDATE_STATUS, 'cronExpression' => null],
            TriggerCatalog::NEW_CUSTOMER => ['type' => AgentTriggerType::EVENT, 'eventName' => TheliaEvents::CUSTOMER_CREATEACCOUNT, 'cronExpression' => null],
            default => throw new \InvalidArgumentException(\sprintf('"%s" has no technical trigger mapping.', $catalogCode)),
        };
    }

    /**
     * The inverse, for the edit page's checkbox pre-fill and the run
     * history's trigger label -- null for a persisted trigger the current
     * catalog no longer recognizes (never fatal, it just won't check a box).
     */
    public static function catalogCodeFor(AgentTrigger $trigger): ?string
    {
        return match (true) {
            $trigger->getType() === AgentTriggerType::CRON => TriggerCatalog::SCHEDULE,
            $trigger->getType() === AgentTriggerType::ABANDONED_CART => TriggerCatalog::CART_ABANDONED,
            $trigger->getType() === AgentTriggerType::LOW_STOCK => TriggerCatalog::LOW_STOCK,
            $trigger->getType() === AgentTriggerType::EVENT && $trigger->getEventName() === TheliaEvents::ORDER_PAY => TriggerCatalog::NEW_ORDER,
            $trigger->getType() === AgentTriggerType::EVENT && $trigger->getEventName() === TheliaEvents::ORDER_UPDATE_STATUS => TriggerCatalog::ORDER_STATUS_CHANGE,
            $trigger->getType() === AgentTriggerType::EVENT && $trigger->getEventName() === TheliaEvents::CUSTOMER_CREATEACCOUNT => TriggerCatalog::NEW_CUSTOMER,
            default => null,
        };
    }
}
