<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CouponGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\OrderGatewayInterface;

/**
 * MYO-236/MYO-247 scenario 6: welcome message on a visitor's first visit,
 * only when a real "first order" coupon is configured. Thelia has no
 * dedicated "new customer only" coupon field, so a coupon counts as a
 * welcome offer when its title/short description/code names it as one
 * (self::KEYWORDS) — the code, discount and condition text themselves are
 * always read from the real coupon row, only the classification is a
 * keyword match. "Once per session" is enforced by ProactiveGuard's
 * scenario-repetition rule, keyed on the signal type below — no extra
 * bookkeeping needed here.
 *
 * MYO-502 AC-a: classification reads findConfiguredCoupons(), not
 * findApplicableCoupons() — first_visit fires on an empty cart, so a
 * welcome coupon carrying its own minimum-amount condition (e.g. WELCOME10
 * ≥ 25€, MYO-467 demo seed) would never "currently match" and the message
 * would stay permanently silent. The condition itself is still surfaced to
 * the visitor via $conditionLabel below, never bypassed.
 */
final readonly class WelcomeCouponScenarioResolver implements ProactiveScenarioResolverInterface
{
    public const SIGNAL_FIRST_VISIT = 'first_visit';

    private const KEYWORDS = [
        'bienvenue', 'welcome',
        'première commande', 'premiere commande', '1ère commande', '1ere commande',
        'first order', 'new customer', 'nouveau client',
    ];

    public function __construct(
        private CouponGatewayInterface $couponGateway,
        private OrderGatewayInterface $orderGateway,
    ) {
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        if ($signal->type !== self::SIGNAL_FIRST_VISIT) {
            return null;
        }

        if ($context->customerId !== null && $this->orderGateway->getOrders($context->customerId, 1, $context) !== []) {
            // Signed in and already ordered before: not a first visit anymore.
            return null;
        }

        foreach ($this->couponGateway->findConfiguredCoupons($context) as $coupon) {
            if (!self::looksLikeWelcomeOffer($coupon)) {
                continue;
            }

            return new ProactiveMessage(
                \sprintf('Bienvenue ! Profitez de %s sur votre première commande avec le code %s.', $coupon['discountLabel'], $coupon['code']),
                couponCode: $coupon['code'],
                conditionLabel: $coupon['shortDescription'] !== '' ? $coupon['shortDescription'] : null,
            );
        }

        return null;
    }

    /**
     * Public so NewsletterOptinScenarioResolver (MYO-475) can reuse the exact
     * same classification instead of duplicating the keyword heuristic.
     *
     * @param array{code: string, title: string, shortDescription: string, discountLabel: string} $coupon
     */
    public static function looksLikeWelcomeOffer(array $coupon): bool
    {
        $haystack = mb_strtolower($coupon['title'].' '.$coupon['shortDescription'].' '.$coupon['code']);

        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
