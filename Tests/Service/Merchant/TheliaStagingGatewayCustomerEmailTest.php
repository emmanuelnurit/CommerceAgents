<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\Merchant\TheliaStagingGateway;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-340: stageCustomerEmail() must work with $ctx->adminId === null, unlike
 * every other stageXxx() method -- cart_abandoned_relaunch and
 * welcome_new_customer only ever run automatically (AgentRunQueue::enqueue()
 * never populates admin_id), so requiring one here would make the whole
 * feature unusable in production.
 */
final class TheliaStagingGatewayCustomerEmailTest extends IntegrationTestCase
{
    private TheliaStagingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new TheliaStagingGateway();
    }

    private function conversationId(): int
    {
        $conversation = (new AgentConversation())
            ->setType('automatic')
            ->setSessionRef('test:'.uniqid('', true));
        $conversation->save();

        return $conversation->getId();
    }

    private function agentDefinitionId(): int
    {
        $definition = (new AgentDefinition())
            ->setCode('staging-gateway-test-'.uniqid('', true))
            ->setTitle('Staging gateway test agent');
        $definition->save();

        return $definition->getId();
    }

    public function testStagesAChangeWithoutAnAdminId(): void
    {
        $conversationId = $this->conversationId();
        $ctx = new ToolContext(isAdmin: true, adminId: null, conversationId: $conversationId, agentDefinitionId: $this->agentDefinitionId());

        $result = $this->gateway->stageCustomerEmail(42, 'jean@example.com', 'Bienvenue', 'Bonjour Jean', $ctx);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(StagedChangeData::STATUS_PENDING, $result['status']);
        $this->assertSame('customer_email', $result['targetType']);
        $this->assertSame(42, $result['targetId']);
        $this->assertSame('jean@example.com', $result['after']['recipient']);
        $this->assertSame('Bienvenue', $result['after']['subject']);
        $this->assertSame('Bonjour Jean', $result['after']['body']);

        $change = AgentStagedChangeQuery::create()->findPk($result['changeId']);
        $this->assertNotNull($change);
        $this->assertNull($change->getAdminId());
    }

    public function testStagesAChangeWithAnAdminIdToo(): void
    {
        $ctx = new ToolContext(isAdmin: true, adminId: 1, conversationId: $this->conversationId(), agentDefinitionId: $this->agentDefinitionId());

        $result = $this->gateway->stageCustomerEmail(42, 'jean@example.com', null, 'Bonjour', $ctx);

        $this->assertArrayNotHasKey('error', $result);
        $change = AgentStagedChangeQuery::create()->findPk($result['changeId']);
        $this->assertSame(1, $change->getAdminId());
    }

    public function testNullSubjectIsAccepted(): void
    {
        $ctx = new ToolContext(isAdmin: true, adminId: null, conversationId: $this->conversationId(), agentDefinitionId: $this->agentDefinitionId());

        $result = $this->gateway->stageCustomerEmail(42, 'jean@example.com', null, 'Bonjour', $ctx);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertNull($result['after']['subject']);
    }

    public function testMissingConversationContextIsRefused(): void
    {
        $ctx = new ToolContext(isAdmin: true, adminId: null, conversationId: null);

        $result = $this->gateway->stageCustomerEmail(42, 'jean@example.com', null, 'Bonjour', $ctx);

        $this->assertArrayHasKey('error', $result);
    }
}
