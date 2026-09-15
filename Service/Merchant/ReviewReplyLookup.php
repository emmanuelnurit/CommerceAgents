<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use CommerceAgents\Model\AgentReviewReplyQuery;

/**
 * Reads approved merchant replies (agent_review_reply) keyed by comment_id,
 * for the BO "Avis" product tab (MYO-352/MYO-358) to overlay under each
 * review. A row only exists once its staged change has been approved (see
 * ReviewReplyApplier), so created_at doubles as the approval date.
 */
final readonly class ReviewReplyLookup
{
    /**
     * @param list<int> $commentIds
     *
     * @return array<int, array{content: string, agentName: ?string, approvedAt: ?\DateTimeInterface}>
     */
    public function findByCommentIds(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }

        $replies = [];
        foreach (AgentReviewReplyQuery::create()->filterByCommentId($commentIds)->find() as $reply) {
            $replies[$reply->getCommentId()] = [
                'content' => $reply->getContent(),
                'agentName' => $reply->getAgentDefinition()?->getTitle(),
                'approvedAt' => $reply->getCreatedAt(),
            ];
        }

        return $replies;
    }
}
