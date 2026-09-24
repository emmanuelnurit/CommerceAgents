<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Controller\Admin;

use Comment\Model\Comment;
use CommerceAgents\Model\AgentActionLog;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentReviewReplyQuery;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\SkillCatalog;
use CommerceAgents\StagedChange\StagedChangeData;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElementsQuery;
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

    /**
     * MYO-472 AC1: a stock change is bucketed "now" (a stockout is time-sensitive),
     * a price change defaults to "watch" (see BriefUrgencyClassifier).
     */
    public function testBriefBucketsPseStockAsUrgentAndPsePriceAsToReview(): void
    {
        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $stockChange = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAdminId($this->adminId)
            ->setTargetType('pse_stock')
            ->setTargetId(1)
            ->setPayloadBefore(json_encode(['quantity' => 5], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode(['quantity' => 40], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $stockChange->save();

        $priceChange = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAdminId($this->adminId)
            ->setTargetType('pse_price')
            ->setTargetId(2)
            ->setPayloadBefore(json_encode(['price' => 20.0], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode(['price' => 18.0], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $priceChange->save();

        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThan(
            0,
            $crawler->filter('.brief-group--now [data-change-id="'.$stockChange->getId().'"]')->count(),
            'a stock change must land in the "to decide now" group',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('.brief-group--watch [data-change-id="'.$priceChange->getId().'"]')->count(),
            'a price change must land in the "to review" group by default',
        );
    }

    /**
     * MYO-472 AC4: the review_reply edit-before-approve (MYO-324) generalizes
     * to pse_price -- the merchant amends the proposed price, not just text.
     */
    public function testApproveWithAnEditedPriceAppliesTheAmendedPrice(): void
    {
        $currency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        self::assertNotNull($currency, 'Test database must have a default currency configured');
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency, ['basePrice' => 20.0]);
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(true)->findOne();

        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $change = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAdminId($this->adminId)
            ->setTargetType('pse_price')
            ->setTargetId($pse->getId())
            ->setPayloadBefore(json_encode(['price' => 20.0, 'promoPrice' => 20.0, 'promo' => false], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode(['price' => 15.0], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        $this->client->request(
            'POST',
            '/admin/merchant-agent/changes/'.$change->getId().'/approve',
            ['_token' => $this->csrfToken(), 'edited_price' => '12.50'],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());
        $price = ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->findOne();
        self::assertEqualsWithDelta(12.50, (float) $price->getPrice(), 0.001);
    }

    /**
     * MYO-472 AC4: a non-numeric edited price is refused up front (422/redirect,
     * matching the existing empty-reply contract) -- never silently coerced.
     */
    public function testApproveRefusesANonNumericEditedPrice(): void
    {
        $currency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $currency, ['basePrice' => 20.0]);
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(true)->findOne();

        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $change = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAdminId($this->adminId)
            ->setTargetType('pse_price')
            ->setTargetId($pse->getId())
            ->setPayloadBefore(json_encode(['price' => 20.0], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode(['price' => 15.0], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        $this->client->request(
            'POST',
            '/admin/merchant-agent/changes/'.$change->getId().'/approve',
            ['_token' => $this->csrfToken(), 'edited_price' => 'not-a-number'],
        );

        $price = ProductPriceQuery::create()->filterByProductSaleElementsId($pse->getId())->findOne();
        self::assertEqualsWithDelta(20.0, (float) $price->getPrice(), 0.001, 'an invalid edit must never reach the applier');
        self::assertSame(StagedChangeData::STATUS_PENDING, \CommerceAgents\Model\AgentStagedChangeQuery::create()->findPk($change->getId())->getStatus());
    }

    /**
     * MYO-472 AC1: a decided change (applied/rejected) is read-only recap in
     * "For your information" -- it must render without Approve/Reject/Edit.
     */
    public function testResolvedChangesAppearInTheInformationGroupWithoutDecisionButtons(): void
    {
        [, $stagedChange] = $this->stageReviewReply('Already decided');
        $stagedChange->setStatus(StagedChangeData::STATUS_APPLIED)->save();

        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $card = $crawler->filter('.brief-group--info [data-change-id="'.$stagedChange->getId().'"]');
        self::assertGreaterThan(0, $card->count());
        self::assertSame(0, $card->filter('form[action*="/approve"]')->count());
        self::assertSame(0, $card->filter('details.brief-card__edit')->count());
    }

    /**
     * MYO-472 AC3: the "Why?" panel surfaces the real tools the agent called
     * for that conversation and links to the full run -- read from
     * agent_action_log/agent_run, not invented.
     */
    public function testWhyPanelListsToolsCalledAndLinksToTheRun(): void
    {
        $conversation = (new AgentConversation())->setType('merchant');
        $conversation->save();

        $agent = (new AgentDefinition())->setCode('myo472-why-'.random_int(1, 1_000_000))->setTitle('Why panel agent');
        $agent->save();

        $run = new AgentRun();
        $run->setAgentDefinitionId($agent->getId())->setStatus('done')->setConversationId($conversation->getId());
        $run->save();

        (new AgentActionLog())
            ->setToolName('get_product_reviews')
            ->setStatus('success')
            ->setChannel('agent')
            ->setConversationId($conversation->getId())
            ->setArguments(json_encode(['productId' => 1], \JSON_THROW_ON_ERROR))
            ->save();

        $change = (new AgentStagedChange())
            ->setConversationId($conversation->getId())
            ->setAdminId($this->adminId)
            ->setTargetType('pse_stock')
            ->setTargetId(1)
            ->setPayloadBefore(json_encode(['quantity' => 5], \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode(['quantity' => 40], \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $panel = $crawler->filter('#why-offcanvas-'.$change->getId());
        self::assertGreaterThan(0, $panel->count());
        self::assertStringContainsString('get_product_reviews', $panel->text());
        self::assertStringContainsString('/admin/module/CommerceAgents/agents/runs/'.$run->getId(), $panel->html());
    }

    /**
     * MYO-519 (AC2 of MYO-508): the skills library tab renders a "Guided
     * settings" button and its modal for an activated skill, but never for
     * the protected conversational copilot (AC3).
     */
    public function testSkillsTabRendersTheGuidedSettingsButtonAndModalForAnActivatedSkill(): void
    {
        $this->getService(SkillCatalog::class)->activate(AgentPresets::STOCK_WATCH_RESTOCK);

        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('[data-testid="skill-guided-settings-stock_watch_restock"]')->count());
        $modal = $crawler->filter('[data-testid="skill-guided-modal-stock_watch_restock"]');
        self::assertGreaterThan(0, $modal->count());
        self::assertGreaterThan(0, $modal->filter('input[name="threshold"][value="5"]')->count());
        self::assertSame(0, $crawler->filter('[data-testid="skill-guided-settings-merchant_assistant"]')->count());
    }

    /**
     * MYO-519 (AC4 of MYO-508): a role_prompt hand-edited in the expert
     * wizard renders the drift banner with the tone selector locked, instead
     * of silently pre-filling a wrong tone.
     */
    public function testSkillsTabRendersTheDriftBannerForAHandEditedRolePrompt(): void
    {
        $skillCatalog = $this->getService(SkillCatalog::class);
        $skillCatalog->activate(AgentPresets::CUSTOMER_REVIEWS_REPLY);
        $definitionId = null;
        foreach ($skillCatalog->all() as $row) {
            if ($row['code'] === AgentPresets::CUSTOMER_REVIEWS_REPLY) {
                $definitionId = $row['agentDefinitionId'];
            }
        }
        AgentDefinitionQuery::create()->findPk($definitionId)->setRolePrompt('Texte tapé à la main par le marchand.')->save();

        $crawler = $this->client->request('GET', '/admin/merchant-agent/changes');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $modal = $crawler->filter('[data-testid="skill-guided-modal-customer_reviews_reply"]');
        self::assertGreaterThan(0, $modal->filter('[data-testid="guided-drift-banner-customer_reviews_reply"]')->count());
        self::assertGreaterThan(0, $modal->filter('button[type="submit"][disabled]')->count());
    }
}
