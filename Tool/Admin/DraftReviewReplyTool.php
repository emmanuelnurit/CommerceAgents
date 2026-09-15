<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Service\ModuleAvailabilityInterface;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;

/**
 * Drafts a reply to a product review (MYO-301): always a PENDING StagedChange
 * proposal, never published directly — the merchant reads the draft and
 * approves or rejects it in the "Proposed changes" console.
 */
final readonly class DraftReviewReplyTool implements ToolInterface
{
    public function __construct(
        private StagingGatewayInterface $stagingGateway,
        private ModuleAvailabilityInterface $moduleAvailability,
    ) {
    }

    public function getName(): string
    {
        return 'draft_review_reply';
    }

    public function getDescription(): string
    {
        return 'Creates a PENDING proposal to reply to a product review, in the shop\'s tone. '
            .'Nothing is published until a human administrator approves it in the approval console.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'review_id' => ['type' => 'integer', 'description' => 'Review id, e.g. from get_product_reviews'],
                'reply' => ['type' => 'string', 'description' => 'The drafted reply text'],
            ],
            'required' => ['review_id', 'reply'],
        ];
    }

    public function getRequiredCapability(): string
    {
        return Capability::REVIEWS_WRITE;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        if (!$this->moduleAvailability->isActive('Comment')) {
            return ['error' => 'The "Comment" module is not active on this shop; product reviews are unavailable.'];
        }

        $reviewId = (int) $args['review_id'];
        if ($reviewId <= 0) {
            return ['error' => 'review_id must be a positive integer'];
        }

        $reply = trim((string) $args['reply']);
        if ($reply === '') {
            return ['error' => 'reply must not be empty'];
        }

        $staged = $this->stagingGateway->stageReviewReply($reviewId, $reply, $ctx);

        if (isset($staged['error'])) {
            return $staged;
        }

        return [
            'staged_change' => $staged,
            'message' => 'Review reply proposal recorded. It requires human approval in the approval console before it is published.',
        ];
    }
}
