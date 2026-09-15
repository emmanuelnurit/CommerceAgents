<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Channel;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\Channel\ChannelConnectorConfigService;
use CommerceAgents\Service\Channel\ChannelSettingsEncryptor;
use CommerceAgents\Service\Channel\TheliaChannelGateway;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-345: same bug family as MYO-343 (TheliaStagingGatewayAutomaticRunTest),
 * one gateway over. TheliaChannelGateway::stageMessage() required both
 * conversationId AND adminId; a run-triggered "Resend this report" (or any
 * future run-only channel send) never has an adminId, so it was silently
 * refused with "No conversation context" instead of queuing the draft.
 */
final class TheliaChannelGatewayAutomaticRunTest extends IntegrationTestCase
{
    private TheliaChannelGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new TheliaChannelGateway(new ChannelConnectorConfigService(new ChannelSettingsEncryptor('test-secret')));
    }

    private function automaticRunContext(): ToolContext
    {
        $conversation = (new AgentConversation())
            ->setType('automatic')
            ->setSessionRef('test:'.uniqid('', true));
        $conversation->save();

        return new ToolContext(isAdmin: true, adminId: null, conversationId: $conversation->getId());
    }

    public function testStageMessageWithoutConversationIdIsRefused(): void
    {
        $ctxWithoutConversation = new ToolContext(isAdmin: true, adminId: null, conversationId: null);

        $result = $this->gateway->stageMessage(1, 'mail', new ChannelMessage(null, 'CA du jour : 999€'), $ctxWithoutConversation);

        $this->assertSame(['error' => 'No conversation context'], $result);
    }

    public function testStageMessageWithoutAdminIdCreatesAStagedChange(): void
    {
        $result = $this->gateway->stageMessage(1, 'mail', new ChannelMessage(null, 'CA du jour : 999€'), $this->automaticRunContext());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(StagedChangeData::STATUS_PENDING, $result['status']);

        $change = AgentStagedChangeQuery::create()->findPk($result['changeId']);
        $this->assertNotNull($change);
        $this->assertNull($change->getAdminId());
    }
}
