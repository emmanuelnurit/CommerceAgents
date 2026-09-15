<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use Comment\Model\Comment;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentReviewReplyQuery;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * MYO-324: the "Proposed changes" screen must render a review_reply proposal
 * as the original customer review + the agent's draft reply, not a raw
 * "PSE #<id>" label and a JSON dump -- and let the merchant amend the draft
 * before approving it.
 */
final class StagedChangesControllerTest extends WebIntegrationTestCase
{
    private AdminSessionInjector $injector;
    private FixtureFactory $factory;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);

        $this->factory = new FixtureFactory($this->getPropelConnection());
        $admin = $this->factory->admin();
        $admin->eraseCredentials();
        $this->adminId = $admin->getId();
        $this->injector->setAdmin($admin);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();
        parent::tearDown();
    }

    /**
     * The CSRF token store is session-backed, and WebIntegrationTestCase does
     * not push a session-bearing request until the client makes one (unlike
     * IntegrationTestCase). Reading the token straight from CsrfTokenManager
     * before any request throws "no session available" -- rendering the page
     * first (which starts the session) and reading its own hidden field is
     * the same value and sidesteps that.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes');

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return array{0: Comment, 1: AgentStagedChange}
     */
    private function stageReviewReply(string $draftReply, ?int $agentDefinitionId = null): array
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());

        $comment = (new Comment())
            ->setUsername('Jane')
            ->setEmail('jane@example.com')
            ->setRef('product')
            ->setRefId($product->getId())
            ->setContent('Great product, fast delivery!')
            ->setRating(4)
            ->setStatus(Comment::ACCEPTED);
        $comment->save();

        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $stagedChange = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAgentDefinitionId($agentDefinitionId)
            ->setAdminId($this->adminId)
            ->setTargetType('review_reply')
            ->setTargetId($comment->getId())
            ->setPayloadBefore(json_encode(['content' => $comment->getContent(), 'rating' => 4], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode(['reply' => $draftReply], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $stagedChange->save();

        return [$comment, $stagedChange];
    }

    public function testListRendersTheOriginalReviewAndTheDraftReply(): void
    {
        [, $stagedChange] = $this->stageReviewReply('Merci pour votre retour !');

        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $row = $crawler->filter('[data-change-id="'.$stagedChange->getId().'"]');
        self::assertGreaterThan(0, $row->count());
        self::assertStringContainsString('Great product, fast delivery!', $row->text());
        self::assertStringContainsString('Merci pour votre retour !', $row->text());
        self::assertStringNotContainsString('PSE #', $row->text());
    }

    public function testApproveWithAnEditedDraftAppliesTheAmendedText(): void
    {
        $proposer = $this->factory->admin();
        [$comment, $stagedChange] = $this->stageReviewReply('Draft from the agent');
        $stagedChange->setAdminId($proposer->getId())->save();

        $this->client->request(
            'POST',
            '/admin/merchant-agent/changes/'.$stagedChange->getId().'/approve',
            ['_token' => $this->csrfToken(), 'reply_content' => 'Edited by the merchant'],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $reply = AgentReviewReplyQuery::create()->filterByCommentId($comment->getId())->findOne();
        self::assertNotNull($reply);
        self::assertSame('Edited by the merchant', $reply->getContent());
    }

    public function testApproveRefusesAnEmptyEditedDraft(): void
    {
        $proposer = $this->factory->admin();
        [$comment, $stagedChange] = $this->stageReviewReply('Draft from the agent');
        $stagedChange->setAdminId($proposer->getId())->save();

        $this->client->request(
            'POST',
            '/admin/merchant-agent/changes/'.$stagedChange->getId().'/approve',
            ['_token' => $this->csrfToken(), 'reply_content' => '   '],
        );

        self::assertNull(AgentReviewReplyQuery::create()->filterByCommentId($comment->getId())->findOne());
    }

    public function testAgentIdFiltersTheList(): void
    {
        $agent = (new AgentDefinition())->setCode('myo324-reviews-'.random_int(1, 1_000_000))->setTitle('Reviews agent');
        $agent->save();
        $otherAgent = (new AgentDefinition())->setCode('myo324-other-'.random_int(1, 1_000_000))->setTitle('Other agent');
        $otherAgent->save();

        [, $matching] = $this->stageReviewReply('Matching', $agent->getId());
        [, $other] = $this->stageReviewReply('Other', $otherAgent->getId());

        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes?agentId='.$agent->getId());

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('[data-change-id="'.$matching->getId().'"]')->count());
        self::assertSame(0, $crawler->filter('[data-change-id="'.$other->getId().'"]')->count());
    }
}
