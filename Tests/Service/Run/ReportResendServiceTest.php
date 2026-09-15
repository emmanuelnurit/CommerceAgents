<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Model\AgentChannel;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\Channel\ChannelConnectorConfigService;
use CommerceAgents\Service\Channel\ChannelSettingsEncryptor;
use CommerceAgents\Service\Channel\TheliaChannelGateway;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\ReportResendService;
use CommerceAgents\StagedChange\StagedChangeData;
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

    /**
     * MYO-345: a "draft" (staged/approval) channel -- e.g. mail -- goes
     * through TheliaChannelGateway::stageMessage() instead of a direct
     * connector. Before the fix, ReportResendService's ToolContext carried
     * neither conversationId nor adminId, so the gateway refused every
     * resend with "No conversation context" and the report was logged
     * 'failed' even though the run itself succeeded.
     */
    public function testResendThroughAStagedChannelSucceedsForARunWithNoAdmin(): void
    {
        $conversation = (new AgentConversation())
            ->setType('automatic')
            ->setSessionRef('test:'.uniqid('', true));
        $conversation->save();

        $definition = (new AgentDefinition())
            ->setCode('resend-staged-test-'.uniqid('', true))
            ->setTitle('Resend staged test agent');
        $definition->save();

        $run = (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setConversationId($conversation->getId())
            ->setStatus(AgentRunQueue::STATUS_DONE)
            ->setSummary('CA du jour : 999€')
            ->setFinishedAt(new \DateTime());
        $run->save();

        $channel = (new AgentChannel())
            ->setAgentDefinitionId($definition->getId())
            ->setConnectorCode('webhook')
            ->setMode('draft')
            ->setEnabled(1);
        $channel->save();

        $registry = new ChannelConnectorRegistry();
        $registry->register(new FakeDirectConnector());
        $gateway = new TheliaChannelGateway(new ChannelConnectorConfigService(new ChannelSettingsEncryptor('test-secret')));
        $service = new ReportResendService(new ChannelBroadcaster($gateway, $registry));

        $outcome = $service->resend($run);

        $this->assertArrayNotHasKey('error', $outcome['results'][0] ?? [], 'Resend must not fail with "No conversation context" once conversationId flows from the run');
        $this->assertSame(StagedChangeData::STATUS_PENDING, $outcome['results'][0]['status']);

        $change = AgentStagedChangeQuery::create()->findPk($outcome['results'][0]['changeId']);
        $this->assertNotNull($change);
        $this->assertSame($conversation->getId(), $change->getConversationId());
        $this->assertNull($change->getAdminId());
    }
}
