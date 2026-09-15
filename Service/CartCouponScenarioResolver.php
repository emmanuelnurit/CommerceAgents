<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CouponGatewayInterface;

/**
 * MYO-236/MYO-247 scenarios 1 and 7: fired on a successful add_to_cart
 * (tool_result add_to_cart in the plan). Scenario 1 alone announces a coupon
 * that already matches the cart; scenario 7 is an extension of the very
 * same signal ("extension du scénario 1" in the plan) that additionally
 * points at the next amount tier not reached yet, when the BO has more than
 * one amount-threshold coupon configured. Both share ProactiveGuard's
 * per-scenario dedupe (keyed on the signal type), so this single resolver
 * covers both rows of the plan's scenario table.
 */
final readonly class CartCouponScenarioResolver implements ProactiveScenarioResolverInterface
{
    public const SIGNAL_ADD_TO_CART = 'add_to_cart';

    public function __construct(
        private CouponGatewayInterface $couponGateway,
        private CartGatewayInterface $cartGateway,
    ) {
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        if ($signal->type !== self::SIGNAL_ADD_TO_CART) {
            return null;
        }

        $applicable = $this->couponGateway->findApplicableCoupons($context);
        if ($applicable === []) {
            // Nothing real to suggest: no data resolution beyond this reaches
            // the LLM, per the tool's guardrail.
            return null;
        }

        $coupon = $applicable[0];
        $conditionLabel = $coupon['shortDescription'] !== '' ? $coupon['shortDescription'] : null;
        $message = \sprintf('Vous débloquez %s avec le code %s !', $coupon['discountLabel'], $coupon['code']);

        $tiered = $this->tierExtension($context);
        if ($tiered === null) {
            return new ProactiveMessage($message, couponCode: $coupon['code'], conditionLabel: $conditionLabel);
        }

        return new ProactiveMessage(
            $message,
            couponCode: $coupon['code'],
            conditionLabel: $conditionLabel,
            progressLabel: $tiered['progressLabel'],
            progressPercent: $tiered['progressPercent'],
            progressAria: $tiered['progressAria'],
            tiers: $tiered['tiers'],
        );
    }

    /**
     * @return array{progressLabel: string, progressPercent: float, progressAria: string, tiers: list<array{threshold: float, label: string, position: float, reached: bool}>}|null
     */
    private function tierExtension(ToolContext $context): ?array
    {
        $ladder = $this->couponGateway->findAmountTierLadder($context);
        if (\count($ladder) < 2) {
            // A ladder needs at least two amount-threshold coupons in the BO
            // to mean anything; one alone is just the plain scenario 1 card.
            return null;
        }

        $next = null;
        foreach ($ladder as $tier) {
            if (!$tier['matching']) {
                $next = $tier;
                break;
            }
        }

        if ($next === null) {
            // Every configured tier is already unlocked, nothing to nudge toward.
            return null;
        }

        $cart = $this->cartGateway->getCart($context);
        $cartTotal = (float) $cart['totalTaxedAmount'];
        $maxThreshold = max(array_column($ladder, 'threshold'));

        $tiers = array_map(static fn (array $tier): array => [
            'threshold' => $tier['threshold'],
            'label' => \sprintf('%s dès %s', $tier['discountLabel'], self::formatAmount($tier['threshold'])),
            'position' => $maxThreshold > 0 ? min(100.0, round($tier['threshold'] / $maxThreshold * 100, 1)) : 0.0,
            'reached' => $tier['matching'],
        ], $ladder);

        $progressPercent = $next['threshold'] > 0 ? min(100.0, round($cartTotal / $next['threshold'] * 100, 1)) : 100.0;

        return [
            'progressLabel' => \sprintf('%s sur %s', self::formatAmount($cartTotal), self::formatAmount($next['threshold'])),
            'progressPercent' => $progressPercent,
            'progressAria' => \sprintf(
                '%s sur %s, prochain palier %s dès %s',
                self::formatAmount($cartTotal),
                self::formatAmount($next['threshold']),
                $next['discountLabel'],
                self::formatAmount($next['threshold']),
            ),
            'tiers' => $tiers,
        ];
    }

    private static function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.').'€';
    }
}
