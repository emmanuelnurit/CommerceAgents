<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Merchant;

use Comment\Model\CommentQuery;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentReviewReplyQuery;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\Tool\Admin\Gateway\StagingGatewayInterface;
use Thelia\Model\CouponQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\OrderCouponQuery;
use Thelia\Model\OrderQuery;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElementsQuery;

final readonly class TheliaStagingGateway implements StagingGatewayInterface
{
    public function stagePriceUpdate(int $pseId, float $newPrice, ?float $newPromoPrice, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null) {
            return ['error' => 'No conversation context'];
        }

        $pse = ProductSaleElementsQuery::create()->findPk($pseId);
        if ($pse === null) {
            return ['error' => 'Variant not found'];
        }

        $defaultCurrency = CurrencyQuery::create()->filterByByDefault(true)->findOne();
        $price = $defaultCurrency !== null
            ? ProductPriceQuery::create()
                ->filterByProductSaleElementsId($pseId)
                ->filterByCurrencyId($defaultCurrency->getId())
                ->findOne()
            : null;

        if ($price === null) {
            return ['error' => 'No price row found for the default currency'];
        }

        $before = [
            'price' => round((float) $price->getPrice(), 2),
            'promoPrice' => round((float) $price->getPromoPrice(), 2),
            'promo' => (bool) $pse->getPromo(),
            'pseRef' => $pse->getRef(),
        ];
        $after = [
            'price' => round($newPrice, 2),
            'promoPrice' => $newPromoPrice !== null ? round($newPromoPrice, 2) : $before['promoPrice'],
            'promo' => $before['promo'],
            'pseRef' => $pse->getRef(),
        ];

        return $this->createChange('pse_price', $pseId, $before, $after, $ctx);
    }

    public function stageStockUpdate(int $pseId, float $newQuantity, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null) {
            return ['error' => 'No conversation context'];
        }

        $pse = ProductSaleElementsQuery::create()->findPk($pseId);
        if ($pse === null) {
            return ['error' => 'Variant not found'];
        }

        $before = ['quantity' => (float) $pse->getQuantity(), 'pseRef' => $pse->getRef()];
        $after = ['quantity' => $newQuantity, 'pseRef' => $pse->getRef()];

        return $this->createChange('pse_stock', $pseId, $before, $after, $ctx);
    }

    public function stageCouponApplication(int $orderId, string $couponCode, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null) {
            return ['error' => 'No conversation context'];
        }

        $order = OrderQuery::create()->findPk($orderId);
        if ($order === null) {
            return ['error' => 'Order not found'];
        }

        $coupon = CouponQuery::create()->filterByIsEnabled(true)->findOneByCode($couponCode);
        if ($coupon === null) {
            return ['error' => \sprintf('No enabled coupon found for code "%s"', $couponCode)];
        }

        $expirationDate = $coupon->getExpirationDate();
        if ($expirationDate !== null && $expirationDate < new \DateTime()) {
            return ['error' => \sprintf('Coupon "%s" has expired', $couponCode)];
        }

        $alreadyApplied = OrderCouponQuery::create()
            ->filterByOrderId($orderId)
            ->filterByCode($couponCode)
            ->exists();
        if ($alreadyApplied) {
            return ['error' => \sprintf('Coupon "%s" is already applied to order %d', $couponCode, $orderId)];
        }

        $before = [
            'orderRef' => $order->getRef(),
            'existingCouponCodes' => array_values(array_filter(array_map(
                static fn ($orderCoupon) => $orderCoupon->getCode(),
                iterator_to_array(OrderCouponQuery::create()->filterByOrderId($orderId)->find()),
            ))),
        ];
        $after = [
            'code' => $coupon->getCode(),
            'type' => $coupon->getType(),
            'amount' => round($coupon->getAmount(), 2),
            'serializedEffects' => $coupon->getSerializedEffects(),
            'title' => $coupon->getTitle(),
            'shortDescription' => $coupon->getShortDescription(),
            'description' => $coupon->getDescription(),
            'expirationDate' => $expirationDate?->format(\DateTimeInterface::ATOM),
            'isCumulative' => (bool) $coupon->getIsCumulative(),
            'isRemovingPostage' => (bool) $coupon->getIsRemovingPostage(),
            'isAvailableOnSpecialOffers' => (bool) $coupon->getIsAvailableOnSpecialOffers(),
            'serializedConditions' => $coupon->getSerializedConditions(),
            'perCustomerUsageCount' => (bool) $coupon->getPerCustomerUsageCount(),
        ];

        return $this->createChange('order_coupon', $orderId, $before, $after, $ctx);
    }

    public function stageReviewReply(int $commentId, string $replyContent, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null) {
            return ['error' => 'No conversation context'];
        }

        $comment = CommentQuery::create()->filterByRef('product')->findPk($commentId);
        if ($comment === null) {
            return ['error' => 'Review not found'];
        }

        if (AgentReviewReplyQuery::create()->filterByCommentId($commentId)->exists()) {
            return ['error' => \sprintf('Review %d already has an approved reply', $commentId)];
        }

        $pendingReplyExists = AgentStagedChangeQuery::create()
            ->filterByTargetType('review_reply')
            ->filterByTargetId($commentId)
            ->filterByStatus(StagedChangeData::STATUS_PENDING)
            ->exists();
        if ($pendingReplyExists) {
            return ['error' => \sprintf('Review %d already has a reply proposal pending approval', $commentId)];
        }

        $before = ['content' => $comment->getContent(), 'rating' => $comment->getRating()];
        $after = ['reply' => $replyContent];

        return $this->createChange('review_reply', $commentId, $before, $after, $ctx);
    }

    public function stageCustomerEmail(int $customerId, string $recipient, ?string $subject, string $body, ToolContext $ctx): array
    {
        if ($ctx->conversationId === null) {
            return ['error' => 'No conversation context'];
        }

        $after = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
        ];

        return $this->createChange('customer_email', $customerId, [], $after, $ctx);
    }

    private function createChange(string $targetType, int $targetId, array $before, array $after, ToolContext $ctx): array
    {
        $change = (new AgentStagedChange())
            ->setConversationId($ctx->conversationId)
            ->setAgentDefinitionId($ctx->agentDefinitionId)
            ->setAdminId($ctx->adminId)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setPayloadBefore(json_encode($before, \JSON_THROW_ON_ERROR))
            ->setPayloadAfter(json_encode($after, \JSON_THROW_ON_ERROR))
            ->setStatus(StagedChangeData::STATUS_PENDING);
        $change->save();

        return [
            'changeId' => $change->getId(),
            'targetType' => $targetType,
            'targetId' => $targetId,
            'before' => $before,
            'after' => $after,
            'status' => StagedChangeData::STATUS_PENDING,
        ];
    }
}
