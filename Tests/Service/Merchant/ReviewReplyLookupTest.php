<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use Comment\Model\Comment;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentReviewReply;
use CommerceAgents\Service\Merchant\ReviewReplyLookup;
use Thelia\Test\IntegrationTestCase;

final class ReviewReplyLookupTest extends IntegrationTestCase
{
    private ReviewReplyLookup $lookup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = new ReviewReplyLookup();
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

    private function agentDefinition(string $title = 'Répondeur d\'avis clients'): AgentDefinition
    {
        $definition = new AgentDefinition();
        $definition
            ->setCode('agent-'.uniqid('', true))
            ->setTitle($title)
            ->setEnabled(1)
            ->save();

        return $definition;
    }

    public function testFindByCommentIdsReturnsTheApprovedReplyForItsComment(): void
    {
        $comment = $this->productReview();
        $agent = $this->agentDefinition();

        $reply = (new AgentReviewReply())
            ->setCommentId($comment->getId())
            ->setAgentDefinitionId($agent->getId())
            ->setContent('Merci pour votre retour !');
        $reply->save();

        $result = $this->lookup->findByCommentIds([$comment->getId()]);

        $this->assertArrayHasKey($comment->getId(), $result);
        $this->assertSame('Merci pour votre retour !', $result[$comment->getId()]['content']);
        $this->assertSame($agent->getTitle(), $result[$comment->getId()]['agentName']);
        $this->assertNotNull($result[$comment->getId()]['approvedAt']);
    }

    public function testFindByCommentIdsOmitsCommentsWithoutAReply(): void
    {
        $comment = $this->productReview();

        $this->assertSame([], $this->lookup->findByCommentIds([$comment->getId()]));
    }

    public function testFindByCommentIdsReturnsEmptyArrayForNoIds(): void
    {
        $this->assertSame([], $this->lookup->findByCommentIds([]));
    }
}
