<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentOutboundMessage;
use CommerceAgents\Model\AgentOutboundMessageQuery;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\Run\AgentRunQueue;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;

/**
 * Real "Results" tab for the daily-sales-summary specialty (MYO-335, lot 1 of
 * MYO-326/MYO-334): the report feed is built from agent_run rows (status
 * done, non-empty summary) over the last WINDOW_DAYS, each cross-referenced
 * to its agent_outbound_message rows by agent_run_id -- never guessed, a run
 * with no matching row is rendered as untracked (see class doc on
 * AgentOutboundMessageLoggerInterface). The mini-chart is shop-wide revenue
 * and order count, independent of this agent, computed directly against
 * Thelia\Model\OrderQuery since the module has no repository equivalent to
 * BackOfficeDefaultTwigBundle's (that one lives in another bundle, not a
 * dependency of this module).
 */
final readonly class DailySalesSummaryResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    private const WINDOW_DAYS = 30;

    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::DAILY_SALES_SUMMARY
            || (\in_array(Capability::ANALYTICS_READ, $capabilities, true) && \in_array(Capability::ORDERS_READ, $capabilities, true));
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_daily_sales_summary.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        $since = new \DateTimeImmutable('-'.self::WINDOW_DAYS.' days');

        $runs = AgentRunQuery::create()
            ->filterByAgentDefinitionId($definition->getId())
            ->filterByStatus(AgentRunQueue::STATUS_DONE)
            ->filterByFinishedAt(['min' => $since])
            ->orderByFinishedAt(Criteria::DESC)
            ->find();

        $reports = $this->buildReports($runs);

        return [
            'agentId' => $definition->getId(),
            'reports' => $reports,
            'hasEverRun' => AgentRunQuery::create()->filterByAgentDefinitionId($definition->getId())->exists(),
            'chart' => $this->chartSeries($since),
        ];
    }

    /**
     * @param \Propel\Runtime\Collection\ObjectCollection<AgentRun> $runs
     *
     * @return list<array{runId: int, finishedAt: ?\DateTimeInterface, summaryLines: list<string>, messages: list<array{channel: string, status: string, sentAt: ?\DateTimeInterface}>}>
     */
    private function buildReports(iterable $runs): array
    {
        $runsWithSummary = [];
        $runIds = [];
        foreach ($runs as $run) {
            $summary = trim((string) $run->getSummary());
            if ($summary === '') {
                // A "done" run with an empty summary reported nothing (MYO-328
                // schema doc): not a report, silently skipped from the feed.
                continue;
            }
            $runsWithSummary[] = [$run, $summary];
            $runIds[] = $run->getId();
        }

        $messagesByRun = [];
        if ($runIds !== []) {
            foreach (AgentOutboundMessageQuery::create()->filterByAgentRunId($runIds, Criteria::IN)->orderBySentAt(Criteria::DESC)->find() as $message) {
                /** @var AgentOutboundMessage $message */
                $messagesByRun[$message->getAgentRunId()][] = [
                    'channel' => $message->getChannel(),
                    'status' => $message->getStatus(),
                    'sentAt' => $message->getSentAt(),
                ];
            }
        }

        return array_map(
            // summary is free-form LLM text (CommerceAgents\Model\AgentRun doc):
            // split into lines here, never `|raw` in the template, so each line
            // still goes through Twig's normal auto-escaping.
            static fn (array $pair): array => [
                'runId' => $pair[0]->getId(),
                'finishedAt' => $pair[0]->getFinishedAt() ?? $pair[0]->getStartedAt(),
                'summaryLines' => preg_split('/\r\n|\r|\n/', $pair[1]),
                'messages' => $messagesByRun[$pair[0]->getId()] ?? [],
            ],
            $runsWithSummary,
        );
    }

    /**
     * @return array{labels: list<string>, revenue: list<float>, orders: list<int>}
     */
    private function chartSeries(\DateTimeImmutable $since): array
    {
        $revenueByDay = [];
        $ordersByDay = [];
        $cursor = \DateTime::createFromImmutable($since)->setTime(0, 0);
        $end = new \DateTime('today');
        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $revenueByDay[$key] = 0.0;
            $ordersByDay[$key] = 0;
            $cursor = $cursor->modify('+1 day');
        }

        foreach (OrderQuery::create()->filterByCreatedAt(['min' => $since])->find() as $order) {
            /** @var Order $order */
            $key = $order->getCreatedAt()->format('Y-m-d');
            if (!\array_key_exists($key, $revenueByDay)) {
                // Defensive only: the query is already bounded by $since.
                continue;
            }
            $revenueByDay[$key] += $order->getTotalAmount();
            ++$ordersByDay[$key];
        }

        return [
            'labels' => array_map(static fn (string $day): string => (new \DateTime($day))->format('d/m'), array_keys($revenueByDay)),
            'revenue' => array_values($revenueByDay),
            'orders' => array_values($ordersByDay),
        ];
    }
}
