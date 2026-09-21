<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

use CommerceAgents\Agent\Tool\ToolContext;

interface CouponGatewayInterface
{
    /**
     * Real, enabled, code-triggered Thelia coupons that already match the
     * current cart (CouponAbstract::isMatching()) and still have usage left
     * for this customer (Coupon::getUsagesLeft()). Never a guess: only rows
     * that exist in the coupon table and pass both checks are returned.
     *
     * @return list<array{code: string, title: string, shortDescription: string, discountLabel: string}>
     */
    public function findApplicableCoupons(ToolContext $ctx): array;

    /**
     * Real, enabled, code-triggered coupons carrying a "cart total amount"
     * condition, usable by this customer, sorted by their threshold
     * ascending — whether the cart already matches them or not. Used to
     * build the amount-tier progress bar (scenario 7): the ladder itself
     * only exists if two coupons like this are configured in the BO, this
     * gateway never invents intermediate thresholds.
     *
     * @return list<array{code: string, title: string, shortDescription: string, discountLabel: string, threshold: float, matching: bool}>
     */
    public function findAmountTierLadder(ToolContext $ctx): array;

    /**
     * Real, enabled, code-triggered coupons usable by this customer —
     * same eligibility base as findApplicableCoupons(), but without
     * requiring the coupon to currently match the cart (MYO-502 AC-a).
     * Needed for scenarios that classify a coupon as a "welcome offer" by
     * its title/code before any cart exists (first visit): a welcome
     * coupon is allowed to carry its own minimum-amount condition, and
     * that condition being unmet on an empty cart must not hide the offer.
     *
     * @return list<array{code: string, title: string, shortDescription: string, discountLabel: string}>
     */
    public function findConfiguredCoupons(ToolContext $ctx): array;
}
