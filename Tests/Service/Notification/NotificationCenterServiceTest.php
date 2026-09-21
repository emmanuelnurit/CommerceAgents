<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Notification;

use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentNotificationAckQuery;
use CommerceAgents\Model\AgentOutboundMessage;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\Notification\NotificationCenterService;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-484 AC2: aggregation across the notification center's three groups
 * (staged_change / outbound_message / brief), and the ack/isolation contract
 * `agent_notification_ack` exists to satisfy.
 */
final class NotificationCenterServiceTest extends IntegrationTestCase
{
    private NotificationCenterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->getService(NotificationCenterService::class);
    }

    private function stagedChange(string $targetType, array $payloadBefore = []): AgentStagedChange
    {
        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $change = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setTargetType($targetType)
            ->setTargetId(random_int(1, 999999))
            ->setPayloadBefore(json_encode($payloadBefore, \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode([], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        return $change;
    }

    private function outboundMessage(): AgentOutboundMessage
    {
        $definition = (new AgentDefinition())
            ->setCode('test-'.uniqid('', true))
            ->setTitle('Test agent');
        $definition->save();

        $run = (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setStatus(AgentRunQueue::STATUS_RUNNING);
        $run->save();

        $message = (new AgentOutboundMessage())
            ->setAgentRunId($run->getId())
            ->setAgentDefinitionId($definition->getId())
            ->setChannel('mail')
            ->setRecipient('client@example.com')
            ->setStatus('sent')
            ->setSentAt(new \DateTime());
        $message->save();

        return $message;
    }

    public function testCountsAreZeroWithNoData(): void
    {
        $summary = $this->service->summaryForAdmin(1);

        $this->assertSame(['total' => 0, 'stagedChange' => 0, 'outboundMessage' => 0, 'brief' => 0], $summary['counts']);
        $this->assertSame([], $summary['items']['stagedChange']);
        $this->assertSame([], $summary['items']['outboundMessage']);
    }

    public function testCountsReflectAMixOfTheThreeGroups(): void
    {
        // pse_stock is always "now" (BriefUrgencyClassifier).
        $this->stagedChange('pse_stock');
        // pse_price is always "watch".
        $this->stagedChange('pse_price');
        $this->outboundMessage();

        $summary = $this->service->summaryForAdmin(1);

        $this->assertSame(2, $summary['counts']['stagedChange']);
        $this->assertSame(1, $summary['counts']['outboundMessage']);
        $this->assertSame(1, $summary['counts']['brief']);
        $this->assertSame(3, $summary['counts']['total']);
        $this->assertCount(2, $summary['items']['stagedChange']);
        $this->assertCount(1, $summary['items']['outboundMessage']);
    }

    public function testAReviewReplyWithALowRatingCountsAsBrief(): void
    {
        $this->stagedChange('review_reply', ['rating' => 1]);

        $summary = $this->service->summaryForAdmin(1);

        $this->assertSame(1, $summary['counts']['brief']);
        $this->assertSame('now', $summary['items']['stagedChange'][0]['urgency']);
    }

    public function testAnAckedItemDisappearsFromTheCount(): void
    {
        $change = $this->stagedChange('pse_stock');

        $this->service->ack(1, NotificationCenterService::TYPE_STAGED_CHANGE, $change->getId());
        $summary = $this->service->summaryForAdmin(1);

        $this->assertSame(0, $summary['counts']['stagedChange']);
        $this->assertSame(0, $summary['counts']['brief']);
        $this->assertSame([], $summary['items']['stagedChange']);
    }

    public function testAckingAnOutboundMessageDoesNotAffectStagedChangeCounts(): void
    {
        $this->stagedChange('pse_stock');
        $message = $this->outboundMessage();

        $this->service->ack(1, NotificationCenterService::TYPE_OUTBOUND_MESSAGE, $message->getId());
        $summary = $this->service->summaryForAdmin(1);

        $this->assertSame(1, $summary['counts']['stagedChange']);
        $this->assertSame(0, $summary['counts']['outboundMessage']);
    }

    public function testAckIsIdempotent(): void
    {
        $change = $this->stagedChange('pse_stock');

        $this->service->ack(1, NotificationCenterService::TYPE_STAGED_CHANGE, $change->getId());
        $this->service->ack(1, NotificationCenterService::TYPE_STAGED_CHANGE, $change->getId());

        $this->assertSame(
            1,
            AgentNotificationAckQuery::create()
                ->filterByAdminId(1)
                ->filterBySourceType(NotificationCenterService::TYPE_STAGED_CHANGE)
                ->filterBySourceId($change->getId())
                ->count(),
        );

        $summary = $this->service->summaryForAdmin(1);
        $this->assertSame(0, $summary['counts']['stagedChange']);
    }

    public function testAckByOneAdminDoesNotExtinguishAnotherAdminsBadge(): void
    {
        $change = $this->stagedChange('pse_stock');

        $this->service->ack(1, NotificationCenterService::TYPE_STAGED_CHANGE, $change->getId());

        $summaryForOtherAdmin = $this->service->summaryForAdmin(2);

        $this->assertSame(1, $summaryForOtherAdmin['counts']['stagedChange']);
    }
}
