<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Merchant;

use Comment\Model\Comment;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\Merchant\TheliaStagingGateway;
use CommerceAgents\StagedChange\StagedChangeData;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * MYO-343: AgentRunQueue::enqueue() never populates $ctx->adminId for
 * automatic runs (LOW_STOCK trigger, abandoned-cart, event-driven) -- only
 * AgentDefinitionManager::runNow() (the BO "Run now" button) does. Requiring
 * $ctx->adminId !== null in stagePriceUpdate()/stageStockUpdate()/
 * stageCouponApplication()/stageReviewReply() silently discarded every
 * proposal a real automatic run tried to create. stageCustomerEmail() already
 * got the correct guard in MYO-340 -- this replicates it on the other four.
 */
final class TheliaStagingGatewayAutomaticRunTest extends IntegrationTestCase
{
    private FixtureFactory $factory;
    private TheliaStagingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->createFixtureFactory();
        $this->gateway = new TheliaStagingGateway();
    }

    private function automaticRunContext(): ToolContext
    {
        $conversation = (new AgentConversation())
            ->setType('automatic')
            ->setSessionRef('test:'.uniqid('', true));
        $conversation->save();

        return new ToolContext(isAdmin: true, adminId: null, conversationId: $conversation->getId());
    }

    private function defaultCurrencyPse(): ProductSaleElements
    {
        $currency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        self::assertNotNull($currency, 'Test database must have a default currency configured');

        $category = $this->factory->category();
        $taxRule = $this->factory->taxRule();
        $product = $this->factory->product($category, $taxRule, $currency, ['basePrice' => 20.0]);

        return ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->filterByIsDefault(true)
            ->findOne();
    }

    private function productReview(): Comment
    {
        $product = $this->factory->product($this->factory->category(), $this->factory->taxRule(), $this->factory->currency());

        $comment = (new Comment())
            ->setUsername('Jane')
            ->setEmail('jane@example.com')
            ->setRef('product')
            ->setRefId($product->getId())
            ->setContent('Great product!')
            ->setRating(5)
            ->setStatus(Comment::ACCEPTED);
        $comment->save();

        return $comment;
    }

    public function testStagePriceUpdateWithoutAdminIdIsRefusedBeforeTheFix(): void
    {
        // Documents the bug this ticket fixes: before the guard change, an
        // automatic run (adminId === null) got a silent "No conversation
        // context" error instead of a staged proposal.
        $pse = $this->defaultCurrencyPse();
        $ctxWithoutAdmin = new ToolContext(isAdmin: true, adminId: null, conversationId: null);

        $result = $this->gateway->stagePriceUpdate($pse->getId(), 15.0, null, $ctxWithoutAdmin);

        $this->assertArrayHasKey('error', $result, 'A context without a conversationId must still be refused');
    }

    public function testStagePriceUpdateWithoutAdminIdCreatesAStagedChange(): void
    {
        $pse = $this->defaultCurrencyPse();

        $result = $this->gateway->stagePriceUpdate($pse->getId(), 15.0, null, $this->automaticRunContext());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(StagedChangeData::STATUS_PENDING, $result['status']);
        $this->assertSame('pse_price', $result['targetType']);

        $change = AgentStagedChangeQuery::create()->findPk($result['changeId']);
        $this->assertNotNull($change);
        $this->assertNull($change->getAdminId());
    }

    public function testStageStockUpdateWithoutAdminIdCreatesAStagedChange(): void
    {
        $pse = $this->defaultCurrencyPse();

        $result = $this->gateway->stageStockUpdate($pse->getId(), 42.0, $this->automaticRunContext());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(StagedChangeData::STATUS_PENDING, $result['status']);
        $this->assertSame('pse_stock', $result['targetType']);

        $change = AgentStagedChangeQuery::create()->findPk($result['changeId']);
        $this->assertNotNull($change);
        $this->assertNull($change->getAdminId());
    }

    public function testStageCouponApplicationWithoutAdminIdCreatesAStagedChange(): void
    {
        $order = $this->factory->order();
        $this->factory->coupon(['code' => 'AUTO10', 'effects' => ['amount' => 10.0]]);

        $result = $this->gateway->stageCouponApplication($order->getId(), 'AUTO10', $this->automaticRunContext());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(StagedChangeData::STATUS_PENDING, $result['status']);
        $this->assertSame('order_coupon', $result['targetType']);

        $change = AgentStagedChangeQuery::create()->findPk($result['changeId']);
        $this->assertNotNull($change);
        $this->assertNull($change->getAdminId());
    }

    public function testStageReviewReplyWithoutAdminIdCreatesAStagedChange(): void
    {
        $comment = $this->productReview();

        $result = $this->gateway->stageReviewReply($comment->getId(), 'Merci pour votre retour !', $this->automaticRunContext());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(StagedChangeData::STATUS_PENDING, $result['status']);
        $this->assertSame('review_reply', $result['targetType']);

        $change = AgentStagedChangeQuery::create()->findPk($result['changeId']);
        $this->assertNotNull($change);
        $this->assertNull($change->getAdminId());
    }
}
