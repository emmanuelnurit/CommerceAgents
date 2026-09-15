<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use Comment\Model\Comment;
use CommerceAgents\Model\AgentReviewReplyQuery;
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

    private function change(int $commentId, array $after): StagedChangeData
    {
        return new StagedChangeData(
            id: 1,
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

        $this->applier->apply($this->change($comment->getId(), ['reply' => 'Merci pour votre retour !']));

        $reply = AgentReviewReplyQuery::create()->filterByCommentId($comment->getId())->findOne();
        $this->assertNotNull($reply);
        $this->assertSame('Merci pour votre retour !', $reply->getContent());
    }

    public function testApplyRefusesWhenReviewNoLongerExists(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->applier->apply($this->change(999999999, ['reply' => 'Merci !']));
    }

    public function testApplyRefusesWhenAlreadyReplied(): void
    {
        $comment = $this->productReview();
        $this->applier->apply($this->change($comment->getId(), ['reply' => 'First reply']));

        $this->expectException(\RuntimeException::class);

        try {
            $this->applier->apply($this->change($comment->getId(), ['reply' => 'Second reply']));
        } finally {
            $this->assertSame(1, AgentReviewReplyQuery::create()->filterByCommentId($comment->getId())->count(), 'No duplicate reply must be created');
        }
    }

    public function testApplyRefusesAnEmptyReply(): void
    {
        $comment = $this->productReview();

        $this->expectException(\RuntimeException::class);

        $this->applier->apply($this->change($comment->getId(), ['reply' => '   ']));
    }
}
