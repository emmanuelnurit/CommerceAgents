<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use CommerceAgents\Model\AgentRun;

/**
 * Queues every due trigger (cron, abandoned cart, low stock), then drains up
 * to `$limit` queued runs. The single execution path shared by the
 * `commerce-agents:run-due` command and the BO pseudo-cron fallback (plan
 * MYO-226 §3.3 point 4), so both stay in lockstep by construction.
 */
final readonly class RunDueService
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private AgentRunQueue $queue,
        private AgentRunner $runner,
    ) {
    }

    /**
     * @return AgentRun[] the runs drained during this pass
     */
    public function run(\DateTimeImmutable $now = new \DateTimeImmutable(), int $limit = self::DEFAULT_LIMIT): array
    {
        $this->queue->enqueueDueCronRuns($now);
        $this->queue->enqueueDueAbandonedCartRuns($now);
        $this->queue->enqueueDueLowStockRuns($now);

        $drained = [];
        foreach ($this->queue->queuedRuns(max(1, $limit)) as $run) {
            $drained[] = $this->runner->executeRun($run, $now);
        }

        return $drained;
    }
}
