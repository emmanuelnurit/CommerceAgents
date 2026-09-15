<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Proactive;

/**
 * A proactive message ready to display in the front chat widget, produced by
 * a scenario resolver once real data confirms the signal deserves one.
 *
 * The coupon fields carry the "carte coupon" data contract from the MYO-245
 * UX spec (document ux-spec-proactive): $couponCode/$conditionLabel for the
 * simple variant, $progressLabel/$progressPercent/$progressAria/$tiers for
 * the amount-tier variant (scenario 7). $couponCode is always a real,
 * already-matching Coupon code (never invented) — the tier data may
 * describe a threshold not yet reached, but every threshold and label still
 * comes from a real coupon row.
 *
 * The product fields carry the "carte produit" contract from the same spec
 * (component 2), used by the cross-sell promo (scenario 2) and low-stock
 * (scenario 5) resolvers. $productStockLabel is a ready-to-display string
 * ("Plus que 3 en stock") computed server-side from the real PSE quantity —
 * the widget only ever renders it, it never composes stock wording itself.
 */
final readonly class ProactiveMessage
{
    /**
     * @param list<array{threshold: float, label: string, position: float, reached: bool}>|null $tiers
     */
    public function __construct(
        public string $message,
        public ?string $couponCode = null,
        public ?string $conditionLabel = null,
        public ?string $progressLabel = null,
        public ?float $progressPercent = null,
        public ?string $progressAria = null,
        public ?array $tiers = null,
        public ?int $productId = null,
        public ?string $productTitle = null,
        public ?string $productUrl = null,
        public ?string $productImageUrl = null,
        public ?float $productPrice = null,
        public ?float $productPromoPrice = null,
        public ?string $productCurrency = null,
        public ?bool $productInStock = null,
        public ?string $productStockLabel = null,
    ) {
    }
}
