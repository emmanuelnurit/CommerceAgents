<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\ReportResendService;
use CommerceAgents\Tests\Tool\Channel\FakeChannelGateway;
use CommerceAgents\Tests\Tool\Channel\FakeDirectConnector;
use CommerceAgents\Tests\Tool\Channel\FakeOutboundMessageLogger;
use CommerceAgents\Tool\Channel\ChannelBroadcaster;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-335: "Resend this report" re-delivers an existing agent_run's summary
 * through the same ChannelBroadcaster path SendToChannelTool uses, logged
 * under that same agent_run_id (a resend is a re-delivery, not a new run).
 */
final class ReportResendServiceTest extends IntegrationTestCase
{
    private function doneRun(string $summary): AgentRun
    {
        $definition = (new AgentDefinition())
            ->setCode('resend-test-'.uniqid('', true))
            ->setTitle('Resend test agent');
        $definition->save();

        $run = (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setStatus(AgentRunQueue::STATUS_DONE)
            ->setSummary($summary)
            ->setFinishedAt(new \DateTime());
        $run->save();

        return $run;
    }

    public function testResendsTheRunsSummaryThroughTheConfiguredChannel(): void
    {
        $run = $this->doneRun('CA du jour : 999€');
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);
        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $outboundLogger = new FakeOutboundMessageLogger();
        $service = new ReportResendService(new ChannelBroadcaster($gateway, $registry, $outboundLogger));

        $outcome = $service->resend($run);

        $this->assertSame('sent', $outcome['results'][0]['status']);
        $this->assertSame('CA du jour : 999€', $connector->sent[0]->body);
        $this->assertSame($run->getId(), $outboundLogger->logged[0]['ctx']->agentRunId);
    }

    public function testRefusesToResendARunWithNoSummary(): void
    {
        $run = $this->doneRun('');
        $service = new ReportResendService(new ChannelBroadcaster(new FakeChannelGateway([]), new ChannelConnectorRegistry()));

        $outcome = $service->resend($run);

        $this->assertSame('This run has no report to resend', $outcome['error']);
    }
}
