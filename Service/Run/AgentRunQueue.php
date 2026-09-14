<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Model\AgentTriggerQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Exception\PropelException;
use Psr\Log\LoggerInterface;

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
    ) {
    }

    /**
     * Queues one run. Returns null when an identical dedup_key is already
     * queued or executed for this agent (idempotent triggers).
     */
    public function enqueue(AgentDefinition $definition, array $context = [], ?AgentTrigger $trigger = null, ?string $dedupKey = null): ?AgentRun
    {
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
     * Turns every due, enabled cron trigger of an enabled agent into a queued
     * run, then advances the trigger schedule.
     *
     * @return AgentRun[] the newly queued runs
     */
    public function enqueueDueCronRuns(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $triggers = AgentTriggerQuery::create()
            ->filterByType('cron')
            ->filterByEnabled(1)
            ->filterByNextRunAt(['max' => $now])
            ->useAgentDefinitionQuery()
                ->filterByEnabled(1)
            ->endUse()
            ->find();

        $runs = [];
        foreach ($triggers as $trigger) {
            $run = $this->enqueue(
                $trigger->getAgentDefinition(),
                ['trigger' => 'cron', 'cron_expression' => $trigger->getCronExpression()],
                $trigger,
                \sprintf('cron:%d:%s', $trigger->getId(), $trigger->getNextRunAt()->format('YmdHis')),
            );
            if ($run !== null) {
                $runs[] = $run;
            }

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
}
