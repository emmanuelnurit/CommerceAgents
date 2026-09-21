<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Notification;

use CommerceAgents\Model\AgentNotificationAck;
use CommerceAgents\Model\AgentNotificationAckQuery;
use CommerceAgents\Model\AgentOutboundMessage;
use CommerceAgents\Model\AgentOutboundMessageQuery;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\StagedChange\BriefUrgencyClassifier;
use CommerceAgents\StagedChange\StagedChangeData;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Translation\Translator;

/**
 * Aggregates the notification center topbar's three sources (MYO-481/483,
 * data layer MYO-484): pending `agent_staged_change` rows, their "Brief now"
 * subset (via {@see BriefUrgencyClassifier} -- not a 4th table, see the
 * parent issue), and `agent_outbound_message` rows. "Unread" is derived from
 * the absence of a matching `agent_notification_ack` row for the requesting
 * admin: two admins never extinguish each other's badge, and acking a Brief
 * "now" item acks the same staged_change row backing it -- there is no
 * separate ack per visual group.
 */
final readonly class NotificationCenterService
{
    public const TYPE_STAGED_CHANGE = 'staged_change';
    public const TYPE_OUTBOUND_MESSAGE = 'outbound_message';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private Translator $translator,
    ) {
    }

    /**
     * @return array{
     *     counts: array{total: int, stagedChange: int, outboundMessage: int, brief: int},
     *     items: array{stagedChange: list<array<string, mixed>>, outboundMessage: list<array<string, mixed>>},
     * }
     */
    public function summaryForAdmin(int $adminId): array
    {
        $ackedStagedChangeIds = $this->ackedSourceIds($adminId, self::TYPE_STAGED_CHANGE);
        $ackedOutboundMessageIds = $this->ackedSourceIds($adminId, self::TYPE_OUTBOUND_MESSAGE);

        $stagedChangeItems = [];
        $briefCount = 0;
        foreach ($this->pendingStagedChanges() as $change) {
            if (\in_array($change->getId(), $ackedStagedChangeIds, true)) {
                continue;
            }

            $urgency = $this->classifyUrgency($change);
            if ($urgency === BriefUrgencyClassifier::NOW) {
                ++$briefCount;
            }

            $stagedChangeItems[] = $this->stagedChangeItem($change, $urgency);
        }

        $outboundItems = [];
        foreach ($this->outboundMessages() as $message) {
            if (\in_array($message->getId(), $ackedOutboundMessageIds, true)) {
                continue;
            }

            $outboundItems[] = $this->outboundMessageItem($message);
        }

        return [
            'counts' => [
                'total' => \count($stagedChangeItems) + \count($outboundItems),
                'stagedChange' => \count($stagedChangeItems),
                'outboundMessage' => \count($outboundItems),
                'brief' => $briefCount,
            ],
            'items' => [
                'stagedChange' => $stagedChangeItems,
                'outboundMessage' => $outboundItems,
            ],
        ];
    }

    /**
     * Idempotent upsert (MYO-484 AC1): replaying the same ack never
     * duplicates a row nor errors, it is a no-op past the first call.
     */
    public function ack(int $adminId, string $sourceType, int $sourceId): void
    {
        $existing = AgentNotificationAckQuery::create()
            ->filterByAdminId($adminId)
            ->filterBySourceType($sourceType)
            ->filterBySourceId($sourceId)
            ->findOne();

        if ($existing !== null) {
            return;
        }

        (new AgentNotificationAck())
            ->setAdminId($adminId)
            ->setSourceType($sourceType)
            ->setSourceId($sourceId)
            ->setAckedAt(new \DateTime())
            ->save();
    }

    /**
     * @return list<int>
     */
    private function ackedSourceIds(int $adminId, string $sourceType): array
    {
        return AgentNotificationAckQuery::create()
            ->filterByAdminId($adminId)
            ->filterBySourceType($sourceType)
            ->select('SourceId')
            ->find()
            ->getData();
    }

    /**
     * @return AgentStagedChange[]
     */
    private function pendingStagedChanges(): array
    {
        return AgentStagedChangeQuery::create()
            ->filterByStatus(StagedChangeData::STATUS_PENDING)
            ->orderById(Criteria::DESC)
            ->find()
            ->getData();
    }

    /**
     * @return AgentOutboundMessage[]
     */
    private function outboundMessages(): array
    {
        return AgentOutboundMessageQuery::create()
            ->orderById(Criteria::DESC)
            ->find()
            ->getData();
    }

    /**
     * `agent_staged_change` has no urgency column (see BriefUrgencyClassifier);
     * the review rating it needs for `review_reply` is read from the
     * snapshot already stored in `payload_before` at staging time (same
     * value TheliaStagingGateway writes), so this never needs to re-fetch
     * the live review.
     */
    private function classifyUrgency(AgentStagedChange $change): string
    {
        $rating = null;
        if ($change->getTargetType() === 'review_reply') {
            $before = json_decode((string) $change->getPayloadBefore(), true) ?? [];
            $rating = \is_numeric($before['rating'] ?? null) ? (int) $before['rating'] : null;
        }

        return BriefUrgencyClassifier::classify($change->getTargetType(), $rating);
    }

    /**
     * @return array<string, mixed>
     */
    private function stagedChangeItem(AgentStagedChange $change, string $urgency): array
    {
        return [
            'sourceType' => self::TYPE_STAGED_CHANGE,
            'sourceId' => $change->getId(),
            'targetType' => $change->getTargetType(),
            'targetId' => $change->getTargetId(),
            'label' => $this->stagedChangeLabel($change),
            'urgency' => $urgency,
            'createdAt' => $change->getCreatedAt()?->format(\DATE_ATOM),
            'url' => $this->urlGenerator->generate('commerceagents_changes'),
        ];
    }

    private function stagedChangeLabel(AgentStagedChange $change): string
    {
        $before = json_decode((string) $change->getPayloadBefore(), true) ?? [];
        $ref = $before['pseRef'] ?? ('#'.$change->getTargetId());

        return match ($change->getTargetType()) {
            'pse_price' => $this->translator->trans('Price adjustment proposed for %ref%', ['%ref%' => $ref], 'commerceagents'),
            'pse_stock' => $this->translator->trans('Stock correction needed for %ref%', ['%ref%' => $ref], 'commerceagents'),
            default => $this->translator->trans('Item #%id%', ['%id%' => $change->getTargetId()], 'commerceagents'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function outboundMessageItem(AgentOutboundMessage $message): array
    {
        return [
            'sourceType' => self::TYPE_OUTBOUND_MESSAGE,
            'sourceId' => $message->getId(),
            'channel' => $message->getChannel(),
            'label' => $this->outboundMessageLabel($message),
            'createdAt' => ($message->getSentAt() ?? $message->getCreatedAt())?->format(\DATE_ATOM),
            'url' => $this->urlGenerator->generate('commerceagents_agents_runs_show', ['id' => $message->getAgentRunId()]),
        ];
    }

    /**
     * Not run through the translator: `channel` is a connector code
     * (`mail`/`slack`/`mattermost`, see ChannelConnectorInterface), not a
     * phrase -- there is nothing here to localize.
     */
    private function outboundMessageLabel(AgentOutboundMessage $message): string
    {
        $target = $message->getRecipient() ?: $message->getBusinessReference();

        return $target !== null && $target !== ''
            ? \sprintf('%s → %s', $message->getChannel(), $target)
            : $message->getChannel();
    }
}
