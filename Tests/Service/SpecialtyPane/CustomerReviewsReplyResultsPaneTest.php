<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\SpecialtyPane;

use Comment\Model\Comment;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\Merchant\TheliaReviewsGateway;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\SpecialtyPane\CustomerReviewsReplyResultsPane;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-336 lot 2 / MYO-338: the real customer-reviews-reply Results pane,
 * built from agent_staged_change rows (target_type = review_reply) decorated
 * live via ReviewsGatewayInterface::findReview() -- never a raw JSON dump of
 * the payload, and never a fatal error when the underlying review was
 * deleted since the proposal was made.
 */
final class CustomerReviewsReplyResultsPaneTest extends IntegrationTestCase
{
    private function pane(): CustomerReviewsReplyResultsPane
    {
        return new CustomerReviewsReplyResultsPane(new TheliaReviewsGateway());
    }

    private function definition(): AgentDefinition
    {
        $definition = (new AgentDefinition())
            ->setCode('reviews-reply-test-'.uniqid('', true))
            ->setTitle('Reviews reply test agent');
        $definition->save();

        return $definition;
    }

    private function review(string $content = 'Great product, fast delivery!'): Comment
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());

        $comment = (new Comment())
            ->setUsername('Jane')
            ->setEmail('jane@example.com')
            ->setRef('product')
            ->setRefId($product->getId())
            ->setContent($content)
            ->setRating(4)
            ->setStatus(Comment::ACCEPTED);
        $comment->save();

        return $comment;
    }

    private function stagedChange(
        ?AgentDefinition $definition,
        string $targetType,
        int $targetId,
        array $payloadBefore,
        array $payloadAfter,
        string $status = StagedChangeData::STATUS_PENDING,
    ): AgentStagedChange {
        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $change = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAgentDefinitionId($definition?->getId())
            ->setAdminId(1)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setPayloadBefore(json_encode($payloadBefore, \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode($payloadAfter, \JSON_THROW_ON_ERROR))
            ->setStatus($status);
        $change->save();

        return $change;
    }

    public function testProposalIncludesTheOriginalReviewAndTheDraftReply(): void
    {
        $definition = $this->definition();
        $comment = $this->review();
        $change = $this->stagedChange(
            $definition,
            'review_reply',
            $comment->getId(),
            ['content' => $comment->getContent(), 'rating' => 4],
            ['reply' => 'Merci pour votre retour !'],
            StagedChangeData::STATUS_PENDING,
        );

        $data = $this->pane()->getViewData($definition);

        $this->assertCount(1, $data['proposals']);
        $proposal = $data['proposals'][0];
        $this->assertSame($change->getId(), $proposal['id']);
        $this->assertSame('Merci pour votre retour !', $proposal['reply']);
        $this->assertSame('pending', $proposal['status']);
        $this->assertNotNull($proposal['review']);
        $this->assertSame('Great product, fast delivery!', $proposal['review']['content']);
        $this->assertSame('Jane', $proposal['review']['author']);
    }

    public function testReviewIsNullWhenTheCommentWasDeletedSinceTheProposalWasMade(): void
    {
        $definition = $this->definition();
        $change = $this->stagedChange(
            $definition,
            'review_reply',
            999_999_999,
            ['content' => 'gone'],
            ['reply' => 'Merci !'],
        );

        $data = $this->pane()->getViewData($definition);

        $this->assertCount(1, $data['proposals']);
        $this->assertNull($data['proposals'][0]['review']);
        $this->assertSame($change->getTargetId(), $data['proposals'][0]['targetId']);
        $this->assertSame('Merci !', $data['proposals'][0]['reply']);
    }

    public function testEmptyStateWhenTheAgentHasNoProposal(): void
    {
        $definition = $this->definition();

        $data = $this->pane()->getViewData($definition);

        $this->assertSame([], $data['proposals']);
        $this->assertSame(0, $data['totalCount']);
        $this->assertFalse($data['hasEverRun']);
    }

    /**
     * MYO-338 scope amendment (CTO arbitration on MYO-336 triage): an agent
     * that ran and read reviews but found nothing to draft must not look
     * identical to one that never ran -- hasEverRun distinguishes the two so
     * the template can point to the run history instead of a plain "nothing
     * yet" message.
     */
    public function testHasEverRunButNoProposalsWhenTheAgentRanWithNothingToPropose(): void
    {
        $definition = $this->definition();
        (new AgentRun())
            ->setAgentDefinitionId($definition->getId())
            ->setStatus(AgentRunQueue::STATUS_DONE)
            ->setStartedAt(new \DateTime())
            ->setFinishedAt(new \DateTime())
            ->save();

        $data = $this->pane()->getViewData($definition);

        $this->assertSame([], $data['proposals']);
        $this->assertTrue($data['hasEverRun']);
    }

    public function testMissingReplyKeyFallsBackToNullRatherThanCrashing(): void
    {
        $definition = $this->definition();
        $comment = $this->review();
        $this->stagedChange($definition, 'review_reply', $comment->getId(), ['content' => $comment->getContent()], []);

        $data = $this->pane()->getViewData($definition);

        $this->assertCount(1, $data['proposals']);
        $this->assertNull($data['proposals'][0]['reply']);
    }

    public function testIgnoresChangesFromAnotherAgent(): void
    {
        $definition = $this->definition();
        $otherDefinition = $this->definition();
        $comment = $this->review();
        $this->stagedChange($otherDefinition, 'review_reply', $comment->getId(), [], ['reply' => 'Other agent reply']);

        $data = $this->pane()->getViewData($definition);

        $this->assertSame([], $data['proposals']);
    }

    public function testIgnoresChangesOfAnotherTargetType(): void
    {
        $definition = $this->definition();
        $this->stagedChange($definition, 'pse_stock', 1, ['quantity' => 0], ['quantity' => 10]);

        $data = $this->pane()->getViewData($definition);

        $this->assertSame([], $data['proposals']);
    }

    public function testCapsAtTwentyMostRecentProposalsAndReportsTheTotal(): void
    {
        $definition = $this->definition();
        $comment = $this->review();
        for ($i = 0; $i < 21; ++$i) {
            $this->stagedChange($definition, 'review_reply', $comment->getId(), [], ['reply' => 'Reply '.$i]);
        }

        $data = $this->pane()->getViewData($definition);

        $this->assertCount(20, $data['proposals']);
        $this->assertSame(21, $data['totalCount']);
        $this->assertSame(20, $data['maxProposals']);
    }
}
