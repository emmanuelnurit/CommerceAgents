<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Audit;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentOutboundMessageQuery;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Service\Audit\TheliaAgentOutboundMessageLogger;
use CommerceAgents\Service\Run\AgentRunQueue;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Test\IntegrationTestCase;

final class TheliaAgentOutboundMessageLoggerTest extends IntegrationTestCase
{
    private function agentRun(): AgentRun
    {
        $definition = (new AgentDefinition())
            ->setCode('test-'.uniqid('', true))
            ->setTitle('Test agent');
        $definition->save();

        $run = (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setStatus(AgentRunQueue::STATUS_RUNNING);
        $run->save();

        return $run;
    }

    public function testLogWritesARowWhenTheContextCarriesARealRun(): void
    {
        $logger = new TheliaAgentOutboundMessageLogger();
        $run = $this->agentRun();
        $ctx = new ToolContext(
            isAdmin: true,
            agentDefinitionId: $run->getAgentDefinitionId(),
            channel: ToolContext::CHANNEL_RUN,
            agentRunId: $run->getId(),
        );

        $logger->log($ctx, 'mail', 'client@example.com', AgentOutboundMessageLoggerInterface::STATUS_SENT);

        $row = AgentOutboundMessageQuery::create()->orderById(Criteria::DESC)->findOne();

        $this->assertNotNull($row);
        $this->assertSame($run->getId(), $row->getAgentRunId());
        $this->assertSame($run->getAgentDefinitionId(), $row->getAgentDefinitionId());
        $this->assertSame('mail', $row->getChannel());
        $this->assertSame('client@example.com', $row->getRecipient());
        $this->assertSame('sent', $row->getStatus());
        $this->assertNotNull($row->getSentAt());
    }

    public function testLogWritesTheErrorMessageOnFailure(): void
    {
        $logger = new TheliaAgentOutboundMessageLogger();
        $run = $this->agentRun();
        $ctx = new ToolContext(
            isAdmin: true,
            agentDefinitionId: $run->getAgentDefinitionId(),
            channel: ToolContext::CHANNEL_RUN,
            agentRunId: $run->getId(),
        );

        $logger->log($ctx, 'mattermost', null, AgentOutboundMessageLoggerInterface::STATUS_FAILED, 'Timeout');

        $row = AgentOutboundMessageQuery::create()->orderById(Criteria::DESC)->findOne();

        $this->assertSame('failed', $row->getStatus());
        $this->assertNull($row->getRecipient());
        $this->assertSame('Timeout', $row->getError());
    }

    public function testLogWritesTheBodyExcerptWhenGiven(): void
    {
        // MYO-340: the "Messages envoyés" panes need a content excerpt per
        // message; existing callers (SendToChannelTool) keep compiling
        // unchanged since the parameter is optional and defaults to null.
        $logger = new TheliaAgentOutboundMessageLogger();
        $run = $this->agentRun();
        $ctx = new ToolContext(
            isAdmin: true,
            agentDefinitionId: $run->getAgentDefinitionId(),
            channel: ToolContext::CHANNEL_RUN,
            agentRunId: $run->getId(),
        );

        $logger->log($ctx, 'mail', 'client@example.com', AgentOutboundMessageLoggerInterface::STATUS_STAGED, null, 'Bonjour, votre panier vous attend…');

        $row = AgentOutboundMessageQuery::create()->orderById(Criteria::DESC)->findOne();

        $this->assertSame('staged', $row->getStatus());
        $this->assertSame('Bonjour, votre panier vous attend…', $row->getBodyExcerpt());
    }

    public function testLogSkipsSilentlyWhenTheContextHasNoRealRun(): void
    {
        $logger = new TheliaAgentOutboundMessageLogger();
        $before = AgentOutboundMessageQuery::create()->count();

        // A merchant-chat call to send_to_channel: agentDefinitionId is set
        // (it's a configurable-agent context) but there is no agent_run --
        // agent_run_id is NOT NULL, so there is nothing truthful to persist,
        // and the tool call itself must never fail because of this.
        $ctx = new ToolContext(isAdmin: true, agentDefinitionId: 1, channel: ToolContext::CHANNEL_CHAT);

        $logger->log($ctx, 'mail', 'client@example.com', AgentOutboundMessageLoggerInterface::STATUS_SENT);

        $this->assertSame($before, AgentOutboundMessageQuery::create()->count());
    }
}
