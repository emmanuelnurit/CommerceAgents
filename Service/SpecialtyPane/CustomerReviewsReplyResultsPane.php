<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\Merchant\TheliaReviewsGateway;
use CommerceAgents\Tool\Admin\Gateway\ReviewsGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Real "Results" tab for the customer-reviews-reply specialty (MYO-336 lot 2,
 * MYO-338): a read-only "Propositions à valider" feed built from
 * agent_staged_change rows (target_type = review_reply) for the current
 * agent, newest first. The review itself (product/excerpt/author) is not in
 * the payload, so it's re-fetched live via ReviewsGatewayInterface::findReview() --
 * null when the review was deleted since the proposal was made, same case
 * already handled by StagedChangesController::decorate() for the approval
 * console. This pane never approves/rejects a change itself -- it links to
 * commerceagents_changes for that.
 *
 * The gateway defaults to the real Thelia implementation rather than being a
 * plain required argument: SpecialtyResultsPaneRegistryTest (MYO-327,
 * explicitly not to be touched by MYO-338) constructs every concrete pane
 * with `new` and no arguments. Autowiring (CommerceAgents.php's
 * SpecificSpecialtyResultsPaneInterface instanceof() block) still injects
 * the aliased service in production regardless of this default.
 */
final readonly class CustomerReviewsReplyResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    private const TARGET_TYPE = 'review_reply';
    private const MAX_PROPOSALS = 20;

    public function __construct(
        private ReviewsGatewayInterface $reviewsGateway = new TheliaReviewsGateway(),
    ) {
    }

    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::CUSTOMER_REVIEWS_REPLY
            || (\in_array(Capability::REVIEWS_READ, $capabilities, true) && \in_array(Capability::REVIEWS_WRITE, $capabilities, true));
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_customer_reviews_reply.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        $totalCount = AgentStagedChangeQuery::create()
            ->filterByAgentDefinitionId($definition->getId())
            ->filterByTargetType(self::TARGET_TYPE)
            ->count();

        $changes = AgentStagedChangeQuery::create()
            ->filterByAgentDefinitionId($definition->getId())
            ->filterByTargetType(self::TARGET_TYPE)
            ->orderByCreatedAt(Criteria::DESC)
            ->limit(self::MAX_PROPOSALS)
            ->find();

        return [
            'agentId' => $definition->getId(),
            'proposals' => array_map($this->toProposal(...), $changes->getData()),
            'totalCount' => $totalCount,
            'maxProposals' => self::MAX_PROPOSALS,
        ];
    }

    /**
     * @return array{id: int, targetId: int, createdAt: ?\DateTimeInterface, status: string, reply: ?string,
     *               review: ?array{id: int, productRef: ?string, productTitle: ?string, rating: ?int,
     *               title: ?string, content: ?string, author: string, createdAt: ?string, hasReply: bool}}
     */
    private function toProposal(AgentStagedChange $change): array
    {
        $after = json_decode((string) $change->getPayloadAfter(), true) ?? [];

        return [
            'id' => $change->getId(),
            'targetId' => $change->getTargetId(),
            'createdAt' => $change->getCreatedAt(),
            'status' => $change->getStatus(),
            'reply' => $after['reply'] ?? null,
            'review' => $this->reviewsGateway->findReview($change->getTargetId()),
        ];
    }
}
