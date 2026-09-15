<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface StagingGatewayInterface
{
    /**
     * @return array {changeId, targetType, targetId, before, after, status} or {error} when the variant is unknown
     */
    public function stagePriceUpdate(int $pseId, float $newPrice, ?float $newPromoPrice, ToolContext $ctx): array;

    /**
     * @return array {changeId, targetType, targetId, before, after, status} or {error}
     */
    public function stageStockUpdate(int $pseId, float $newQuantity, ToolContext $ctx): array;

    /**
     * @return array {changeId, targetType, targetId, before, after, status} or {error}
     *                                                                       when the order/coupon is unknown or the coupon is already applied
     */
    public function stageCouponApplication(int $orderId, string $couponCode, ToolContext $ctx): array;

    /**
     * @return array {changeId, targetType, targetId, before, after, status} or {error}
     *                                                                       when the review is unknown or already has a reply proposal pending
     */
    public function stageReviewReply(int $commentId, string $replyContent, ToolContext $ctx): array;

    /**
     * A third-party action (send an e-mail to a customer), never an in-place
     * modification -- same shape as stageCouponApplication().
     *
     * @return array {changeId, targetType, targetId, before, after, status} or {error}
     */
    public function stageCustomerEmail(int $customerId, string $recipient, ?string $subject, string $body, ToolContext $ctx): array;
}
