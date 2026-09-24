<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Model\AgentTriggerQuery;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Psr\Log\LoggerInterface;
use Thelia\Model\Cart;

/**
 * Inserts and lists the pending agent_run rows. Execution never happens here:
 * runs are drained by the commerce-agents:run-due command (plan MYO-226 §3.3).
 */
final readonly class AgentRunQueue
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED_BUDGET = 'skipped_budget';

    public function __construct(
        private LoggerInterface $logger,
        private AbandonedCartFinder $abandonedCartFinder,
        private LowStockFinder $lowStockFinder,
        private AssistantLocaleResolver $localeResolver,
    ) {
    }

    /**
     * Queues one run. Returns null when an identical dedup_key is already
     * queued or executed for this agent (idempotent triggers), or when the
     * trigger's daily proposal cap (MYO-508 AC3) is already reached today.
     */
    public function enqueue(AgentDefinition $definition, array $context = [], ?AgentTrigger $trigger = null, ?string $dedupKey = null): ?AgentRun
    {
        if ($trigger !== null && $this->dailyCapReached($trigger)) {
            $this->logger->info('[commerce-agents] run skipped: daily proposal cap reached', [
                'agent' => $definition->getCode(),
                'trigger_id' => $trigger->getId(),
            ]);

            return null;
        }

        $run = (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setAgentTriggerId($trigger?->getId())
            ->setStatus(self::STATUS_QUEUED)
            ->setDedupKey($dedupKey)
            ->setContext(json_encode($context, \JSON_THROW_ON_ERROR));

        try {
            $run->save();
        } catch (PropelException $exception) {
            if ($dedupKey === null) {
                throw $exception;
            }
            $this->logger->info('[commerce-agents] run deduplicated', [
                'agent' => $definition->getCode(),
                'dedup_key' => $dedupKey,
            ]);

            return null;
        }

        return $run;
    }

    /**
     * Queues a manual run (« Exécuter maintenant »). The acting administrator
     * id, when known, is kept in the context so staged changes created by the
     * run carry a human author.
     */
    public function enqueueManual(AgentDefinition $definition, array $context = [], ?int $adminId = null): AgentRun
    {
        $context['trigger'] = 'manual';
        if ($adminId !== null) {
            $context['admin_id'] = $adminId;
        }

        /** @var AgentRun $run manual runs carry no dedup key, enqueue() cannot return null */
        $run = $this->enqueue($definition, $context);

        return $run;
    }

    /**
     * Turns a whitelisted Thelia event into queued runs for every enabled
     * agent with a matching, enabled `event` trigger whose conditions the
     * event context satisfies (plan MYO-226 §3.3 point 3). Never touches the
     * LLM: this is the only path an event subscriber is allowed to call.
     *
     * @param array<string, mixed> $conditionContext keys {@see TriggerConditions} understands, e.g. 'amount', 'status_id'
     * @param array<string, mixed> $runContext       extra data stored on the queued run's context
     *
     * @return AgentRun[] the newly queued runs
     */
    public function enqueueForEvent(string $eventName, string $dedupKey, array $conditionContext = [], array $runContext = []): array
    {
        $triggers = AgentTriggerQuery::create()
            ->filterByType(AgentTriggerType::EVENT)
            ->filterByEventName($eventName)
            ->filterByEnabled(1)
            ->useAgentDefinitionQuery()
                ->filterByEnabled(1)
            ->endUse()
            ->find();

        $runs = [];
        foreach ($triggers as $trigger) {
            if (!TriggerConditions::matches($trigger->getConditions(), $conditionContext)) {
                continue;
            }

            $run = $this->enqueue(
                $trigger->getAgentDefinition(),
                ['trigger' => AgentTriggerType::EVENT, 'event_name' => $eventName] + $runContext,
                $trigger,
                $dedupKey,
            );
            if ($run !== null) {
                $runs[] = $run;
            }
        }

        return $runs;
    }

    /**
     * Turns every due, enabled cron trigger of an enabled agent into a queued
     * run, then advances the trigger schedule.
     *
     * @return AgentRun[] the newly queued runs
     */
    public function enqueueDueCronRuns(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $runs = [];
        foreach ($this->dueTriggers(AgentTriggerType::CRON, $now) as $trigger) {
            $run = $this->enqueue(
                $trigger->getAgentDefinition(),
                ['trigger' => AgentTriggerType::CRON, 'cron_expression' => $trigger->getCronExpression()],
                $trigger,
                \sprintf('cron:%d:%s', $trigger->getId(), $trigger->getNextRunAt()->format('YmdHis')),
            );
            if ($run !== null) {
                $runs[] = $run;
            }

            $this->advanceSchedule($trigger, $now);
        }

        return $runs;
    }

    /**
     * Business-cron trigger (plan MYO-226 §3.3 point 4): no native Thelia
     * event fires for an abandoned cart, so the schedule itself runs the
     * detection query — one queued run per cart still found abandoned,
     * deduplicated per day so a cart that stays abandoned is not spammed at
     * every drain.
     *
     * The queued context carries the real `customer_id` and `cart_items`
     * resolved from the cart right here (MYO-363): the catalogue has no tool
     * that turns a bare `cart_id` into a customer, so leaving that to the LLM
     * meant it either guessed (the numeric cart id used as a customer id) or
     * invented cart contents outright. Handing over the already-known,
     * server-resolved facts removes the guess entirely instead of adding a
     * tool the model could still skip.
     *
     * `min_amount` (MYO-508 AC3, "ne relancer que les paniers de plus de X €")
     * is evaluated here against the cart's untaxed total through the same
     * `TriggerConditions::matches()` used for event triggers — carts under
     * the threshold are silently skipped, never queued.
     *
     * @return AgentRun[] the newly queued runs
     */
    public function enqueueDueAbandonedCartRuns(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $runs = [];
        foreach ($this->dueTriggers(AgentTriggerType::ABANDONED_CART, $now) as $trigger) {
            $delayHours = TriggerConditions::intOption($trigger->getConditions(), 'delay_hours', AbandonedCartFinder::DEFAULT_DELAY_HOURS);

            foreach ($this->abandonedCartFinder->find($delayHours, $now) as $cart) {
                if (!TriggerConditions::matches($trigger->getConditions(), ['amount' => $cart->getTotalAmount()])) {
                    continue;
                }

                $run = $this->enqueue(
                    $trigger->getAgentDefinition(),
                    ['trigger' => AgentTriggerType::ABANDONED_CART] + $this->abandonedCartContext($cart),
                    $trigger,
                    \sprintf('abandoned_cart:%d:%d:%s', $trigger->getId(), $cart->getId(), $now->format('Ymd')),
                );
                if ($run !== null) {
                    $runs[] = $run;
                }
            }

            $this->advanceSchedule($trigger, $now);
        }

        return $runs;
    }

    /**
     * Business-cron trigger (plan MYO-226 §3.3 point 4): same shape as
     * abandoned carts, one queued run per sale element still under its
     * trigger's threshold, deduplicated per day.
     *
     * @return AgentRun[] the newly queued runs
     */
    public function enqueueDueLowStockRuns(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $runs = [];
        foreach ($this->dueTriggers(AgentTriggerType::LOW_STOCK, $now) as $trigger) {
            $threshold = TriggerConditions::intOption($trigger->getConditions(), 'threshold', LowStockFinder::DEFAULT_THRESHOLD);

            foreach ($this->lowStockFinder->find($threshold) as $pse) {
                $run = $this->enqueue(
                    $trigger->getAgentDefinition(),
                    ['trigger' => AgentTriggerType::LOW_STOCK, 'product_sale_elements_id' => $pse->getId(), 'threshold' => $threshold],
                    $trigger,
                    \sprintf('low_stock:%d:%d:%s', $trigger->getId(), $pse->getId(), $now->format('Ymd')),
                );
                if ($run !== null) {
                    $runs[] = $run;
                }
            }

            $this->advanceSchedule($trigger, $now);
        }

        return $runs;
    }

    /**
     * @return AgentRun[] oldest first
     */
    public function queuedRuns(int $limit): array
    {
        return AgentRunQuery::create()
            ->filterByStatus(self::STATUS_QUEUED)
            ->orderById(Criteria::ASC)
            ->limit($limit)
            ->find()
            ->getData();
    }

    /**
     * MYO-508 AC3 "plafond quotidien de propositions": `conditions.max_per_day`
     * caps how many runs a trigger may queue per calendar day, counted
     * against `agent_run` (already existing table, zero schema change). No
     * value or a value ≤ 0 means unlimited, the settings screen's default.
     */
    private function dailyCapReached(AgentTrigger $trigger): bool
    {
        $maxPerDay = TriggerConditions::intOption($trigger->getConditions(), 'max_per_day', 0);
        if ($maxPerDay <= 0) {
            return false;
        }

        $runsToday = AgentRunQuery::create()
            ->filterByAgentTriggerId($trigger->getId())
            ->filterByCreatedAt(['min' => new \DateTimeImmutable('today')])
            ->count();

        return $runsToday >= $maxPerDay;
    }

    /**
     * @return AgentTrigger[]
     */
    private function dueTriggers(string $type, \DateTimeImmutable $now): array
    {
        return AgentTriggerQuery::create()
            ->filterByType($type)
            ->filterByEnabled(1)
            ->filterByNextRunAt(['max' => $now])
            ->useAgentDefinitionQuery()
                ->filterByEnabled(1)
            ->endUse()
            ->find()
            ->getData();
    }

    /**
     * @return array{cart_id: int, customer_id: int, cart_items: list<array{product_id: int, title: string, quantity: int}>}
     */
    private function abandonedCartContext(Cart $cart): array
    {
        $locale = $this->localeResolver->forAgentRun();

        $items = [];
        foreach ($cart->getCartItems() as $cartItem) {
            $items[] = [
                'product_id' => (int) $cartItem->getProductId(),
                'title' => $cartItem->getProduct()->setLocale($locale)->getTitle(),
                'quantity' => (int) $cartItem->getQuantity(),
            ];
        }

        return [
            'cart_id' => $cart->getId(),
            // AbandonedCartFinder::find() only ever returns carts with a
            // customer attached (MYO-363): this is never null here.
            'customer_id' => (int) $cart->getCustomerId(),
            'cart_items' => $items,
        ];
    }

    private function advanceSchedule(AgentTrigger $trigger, \DateTimeImmutable $now): void
    {
        $trigger->setLastRunAt($now);
        try {
            $next = $trigger->getCronExpression() !== null
                ? CronSchedule::nextRunDate($trigger->getCronExpression(), $now)
                : null;
        } catch (\InvalidArgumentException $exception) {
            // A malformed expression must not be retried on every drain.
            $next = null;
            $this->logger->error('[commerce-agents] cron trigger unscheduled: '.$exception->getMessage(), [
                'trigger_id' => $trigger->getId(),
            ]);
        }
        $trigger->setNextRunAt($next !== null ? \DateTime::createFromImmutable($next) : null);
        $trigger->save();
    }
}
