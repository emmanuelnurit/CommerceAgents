<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use Comment\Model\CommentQuery;
use CommerceAgents\Model\AgentReviewReply;
use CommerceAgents\Model\AgentReviewReplyQuery;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\StagedChange\ChangeApplierInterface;
use CommerceAgents\StagedChange\StagedChangeData;

/**
 * Persists an approved review reply draft (MYO-301). The core "Comment"
 * module has no native reply concept, so CommerceAgents owns the reply row
 * in its own table; nothing on the underlying comment/review is mutated.
 */
final readonly class ReviewReplyApplier implements ChangeApplierInterface
{
    public function getTargetType(): string
    {
        return 'review_reply';
    }

    public function apply(StagedChangeData $change): void
    {
        $comment = CommentQuery::create()->filterByRef('product')->findPk($change->targetId);
        if ($comment === null) {
            throw new \RuntimeException(\sprintf('Review %d no longer exists; refusing to apply a stale proposal', $change->targetId));
        }

        if (AgentReviewReplyQuery::create()->filterByCommentId($change->targetId)->exists()) {
            throw new \RuntimeException(\sprintf('Review %d already has an approved reply; refusing to apply a stale proposal', $change->targetId));
        }

        $reply = $change->payloadAfter['reply'] ?? null;
        if (!\is_string($reply) || trim($reply) === '') {
            throw new \RuntimeException(\sprintf('Staged change %d has no reply text to apply', $change->id));
        }

        $stagedChange = AgentStagedChangeQuery::create()->findPk($change->id);

        (new AgentReviewReply())
            ->setCommentId($change->targetId)
            ->setStagedChangeId($change->id)
            ->setAgentDefinitionId($stagedChange?->getAgentDefinitionId())
            ->setAdminId($change->proposedBy)
            ->setContent($reply)
            ->save();
    }
}
