<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use Comment\Model\Comment;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentReviewReplyQuery;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\Merchant\ReviewReplyApplier;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Test\IntegrationTestCase;

final class ReviewReplyApplierTest extends IntegrationTestCase
{
    private ReviewReplyApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applier = new ReviewReplyApplier();
    }

    /**
     * apply() persists AgentReviewReply.staged_change_id as a real foreign
     * key: the id must reference an actual agent_staged_change row (auto-increment
     * values are never rolled back between tests, so a hardcoded id is not safe here).
     */
    private function stageReviewReplyChange(int $commentId): AgentStagedChange
    {
        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $stagedChange = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAdminId(1)
            ->setTargetType('review_reply')
            ->setTargetId($commentId)
            ->setPayloadBefore('{}')
            ->setPayloadAfter('{}')
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $stagedChange->save();

        return $stagedChange;
    }

    private function change(int $id, int $commentId, array $after): StagedChangeData
    {
        return new StagedChangeData(
            id: $id,
            targetType: 'review_reply',
            targetId: $commentId,
            payloadBefore: [],
            payloadAfter: $after,
            status: StagedChangeData::STATUS_PENDING,
        );
    }

    private function productReview(string $content = 'Great product!'): Comment
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());

        $comment = (new Comment())
            ->setUsername('Jane')
            ->setEmail('jane@example.com')
            ->setRef('product')
            ->setRefId($product->getId())
            ->setContent($content)
            ->setRating(5)
            ->setStatus(Comment::ACCEPTED);
        $comment->save();

        return $comment;
    }

    public function testApplyCreatesTheReviewReplyRow(): void
    {
        $comment = $this->productReview();
        $stagedChange = $this->stageReviewReplyChange($comment->getId());

        $this->applier->apply($this->change($stagedChange->getId(), $comment->getId(), ['reply' => 'Merci pour votre retour !']));

        $reply = AgentReviewReplyQuery::create()->filterByCommentId($comment->getId())->findOne();
        $this->assertNotNull($reply);
        $this->assertSame('Merci pour votre retour !', $reply->getContent());
        $this->assertSame($stagedChange->getId(), $reply->getStagedChangeId());
    }

    public function testApplyRefusesWhenReviewNoLongerExists(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->applier->apply($this->change(1, 999999999, ['reply' => 'Merci !']));
    }

    public function testApplyRefusesWhenAlreadyReplied(): void
    {
        $comment = $this->productReview();
        $firstChange = $this->stageReviewReplyChange($comment->getId());
        $this->applier->apply($this->change($firstChange->getId(), $comment->getId(), ['reply' => 'First reply']));

        $secondChange = $this->stageReviewReplyChange($comment->getId());
        $this->expectException(\RuntimeException::class);

        try {
            $this->applier->apply($this->change($secondChange->getId(), $comment->getId(), ['reply' => 'Second reply']));
        } finally {
            $this->assertSame(1, AgentReviewReplyQuery::create()->filterByCommentId($comment->getId())->count(), 'No duplicate reply must be created');
        }
    }

    public function testApplyRefusesAnEmptyReply(): void
    {
        $comment = $this->productReview();

        $this->expectException(\RuntimeException::class);

        $this->applier->apply($this->change(1, $comment->getId(), ['reply' => '   ']));
    }
}
