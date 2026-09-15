<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Audit;

use CommerceAgents\Agent\Tool\AgentActionLoggerInterface;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentActionLogQuery;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\Audit\TheliaAgentActionLogger;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Test\IntegrationTestCase;

final class TheliaAgentActionLoggerTest extends IntegrationTestCase
{
    private function agentDefinitionId(): int
    {
        $definition = (new AgentDefinition())
            ->setCode('test-'.uniqid('', true))
            ->setTitle('Test agent');
        $definition->save();

        return $definition->getId();
    }

    public function testLogWritesARowWithChannelAndContext(): void
    {
        $logger = new TheliaAgentActionLogger();
        $definitionId = $this->agentDefinitionId();
        $ctx = new ToolContext(isAdmin: true, adminId: 7, agentDefinitionId: $definitionId, channel: ToolContext::CHANNEL_MCP);

        $logger->log('get_customer_profile', 'customer.profile.read', AgentActionLoggerInterface::STATUS_SUCCESS, $ctx, ['customer_id' => 42]);

        $row = AgentActionLogQuery::create()->orderById(Criteria::DESC)->findOne();

        $this->assertNotNull($row);
        $this->assertSame('get_customer_profile', $row->getToolName());
        $this->assertSame('customer.profile.read', $row->getCapability());
        $this->assertSame('mcp', $row->getChannel());
        $this->assertSame('success', $row->getStatus());
        $this->assertSame(7, $row->getAdminId());
        $this->assertSame($definitionId, $row->getAgentDefinitionId());
        $this->assertStringContainsString('42', (string) $row->getArguments());
    }

    public function testLogWritesTheErrorMessageWhenDenied(): void
    {
        $logger = new TheliaAgentActionLogger();
        $ctx = new ToolContext(isAdmin: false, customerId: 42);

        $logger->log('apply_coupon_to_order', 'orders.write', AgentActionLoggerInterface::STATUS_DENIED, $ctx, [], 'Tool "apply_coupon_to_order" not allowed in this context');

        $row = AgentActionLogQuery::create()->orderById(Criteria::DESC)->findOne();

        $this->assertSame('denied', $row->getStatus());
        $this->assertStringContainsString('not allowed', (string) $row->getError());
        $this->assertSame(42, $row->getCustomerId());
    }
}
