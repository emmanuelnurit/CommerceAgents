<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentOutboundMessage;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\SpecialtyPane\DailySalesSummaryResultsPane;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-335: the real daily-sales-summary Results pane, built from agent_run +
 * agent_outbound_message rows -- never a guessed/invented send status.
 */
final class DailySalesSummaryResultsPaneTest extends IntegrationTestCase
{
    private function definition(): AgentDefinition
    {
        $definition = (new AgentDefinition())
            ->setCode('daily-sales-test-'.uniqid('', true))
            ->setTitle('Daily sales summary test agent');
        $definition->save();

        return $definition;
    }

    private function doneRun(AgentDefinition $definition, string $summary, ?\DateTimeInterface $finishedAt = null): AgentRun
    {
        $run = (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setStatus(AgentRunQueue::STATUS_DONE)
            ->setSummary($summary)
            ->setStartedAt($finishedAt ?? new \DateTime())
            ->setFinishedAt($finishedAt ?? new \DateTime());
        $run->save();

        return $run;
    }

    public function testReportIncludesTheTrackedSendStatus(): void
    {
        $definition = $this->definition();
        $run = $this->doneRun($definition, "CA du jour : 1234€\n12 commandes.");
        (new AgentOutboundMessage())
            ->setAgentRunId($run->getId())
            ->setAgentDefinitionId($definition->getId())
            ->setChannel('mattermost')
            ->setStatus(AgentOutboundMessageLoggerInterface::STATUS_SENT)
            ->setSentAt(new \DateTime())
            ->save();

        $data = (new DailySalesSummaryResultsPane())->getViewData($definition);

        $this->assertCount(1, $data['reports']);
        $report = $data['reports'][0];
        $this->assertSame($run->getId(), $report['runId']);
        $this->assertSame(['CA du jour : 1234€', '12 commandes.'], $report['summaryLines']);
        $this->assertCount(1, $report['messages']);
        $this->assertSame('mattermost', $report['messages'][0]['channel']);
        $this->assertSame('sent', $report['messages'][0]['status']);
    }

    public function testReportIncludesAFailedSendStatus(): void
    {
        $definition = $this->definition();
        $run = $this->doneRun($definition, 'Rapport du jour');
        (new AgentOutboundMessage())
            ->setAgentRunId($run->getId())
            ->setAgentDefinitionId($definition->getId())
            ->setChannel('mail')
            ->setStatus(AgentOutboundMessageLoggerInterface::STATUS_FAILED)
            ->setError('Timeout')
            ->setSentAt(new \DateTime())
            ->save();

        $data = (new DailySalesSummaryResultsPane())->getViewData($definition);

        $this->assertSame('failed', $data['reports'][0]['messages'][0]['status']);
    }

    public function testReportHasNoMessagesWhenNothingWasLogged(): void
    {
        // A done run with no matching agent_outbound_message row (e.g. no
        // channel configured yet, or a silent audit-log failure) must render
        // as untracked, never as a fabricated "sent".
        $definition = $this->definition();
        $this->doneRun($definition, 'Rapport du jour sans canal configuré');

        $data = (new DailySalesSummaryResultsPane())->getViewData($definition);

        $this->assertCount(1, $data['reports']);
        $this->assertSame([], $data['reports'][0]['messages']);
    }

    public function testEmptyStateWhenTheAgentHasNeverRun(): void
    {
        $definition = $this->definition();

        $data = (new DailySalesSummaryResultsPane())->getViewData($definition);

        $this->assertSame([], $data['reports']);
        $this->assertFalse($data['hasEverRun']);
    }

    public function testHasEverRunButNoRecentReportWhenTheOnlyRunIsOutsideTheWindow(): void
    {
        $definition = $this->definition();
        $this->doneRun($definition, 'Vieux rapport', new \DateTime('-40 days'));

        $data = (new DailySalesSummaryResultsPane())->getViewData($definition);

        $this->assertSame([], $data['reports']);
        $this->assertTrue($data['hasEverRun']);
    }

    public function testSkipsADoneRunWithAnEmptySummary(): void
    {
        // AgentRunner::finish() persists null when the LLM produced nothing
        // to report (MYO-328 doc on the summary column): not a report.
        $definition = $this->definition();
        $this->doneRun($definition, '');

        $data = (new DailySalesSummaryResultsPane())->getViewData($definition);

        $this->assertSame([], $data['reports']);
    }

    public function testChartCoversTheFullThirtyDayWindow(): void
    {
        $definition = $this->definition();

        $data = (new DailySalesSummaryResultsPane())->getViewData($definition);

        $this->assertCount(31, $data['chart']['labels']);
        $this->assertCount(31, $data['chart']['revenue']);
        $this->assertCount(31, $data['chart']['orders']);
    }
}
