<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Tool\Shopping\Gateway\CouponGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CustomerGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\NewsletterGatewayInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Thelia\Core\Translation\Translator;

/**
 * MYO-471/MYO-475 T7: opt-in-newsletter-for-a-coupon, fired on the same
 * add_to_cart signal as CartCouponScenarioResolver (F2/F3). Registered with
 * a higher tag priority so it is tried first in ProactiveScenarioRegistry's
 * iterator — no change to the registry or to CartCouponScenarioResolver
 * itself: whenever this resolver has nothing to offer it returns null and
 * the existing coupon resolver takes over normally, guaranteeing a single
 * message per signal.
 *
 * Two conditions specific to this resolver (the other two — ProactiveGuard
 * gate and the dismissed flag — are already enforced upstream, before the
 * registry is even called, in ChatController::proactiveCheck()):
 *  1. the visitor is not already subscribed;
 *  2. a real "welcome" coupon is configured in the BO (same keyword
 *     detection as WelcomeCouponScenarioResolver, reused rather than
 *     duplicated).
 *
 * The coupon code itself is never put on the message: it only ever reaches
 * the visitor by e-mail, once they actually opt in (NewsletterOptinService).
 */
#[AsTaggedItem(priority: 10)]
final readonly class NewsletterOptinScenarioResolver implements ProactiveScenarioResolverInterface
{
    public function __construct(
        private CouponGatewayInterface $couponGateway,
        private CustomerGatewayInterface $customerGateway,
        private NewsletterGatewayInterface $newsletterGateway,
        private Translator $translator,
    ) {
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        if ($signal->type !== CartCouponScenarioResolver::SIGNAL_ADD_TO_CART) {
            return null;
        }

        if ($this->isAlreadySubscribed($context)) {
            return null;
        }

        $coupon = $this->findWelcomeCoupon($context);
        if ($coupon === null) {
            return null;
        }

        $message = $this->translator->trans(
            'Get %discount% by e-mail: subscribe to our newsletter!',
            ['%discount%' => $coupon['discountLabel']],
            CommerceAgents::DOMAIN_NAME,
            $context->locale,
        );

        return new ProactiveMessage(
            $message,
            conditionLabel: $coupon['shortDescription'] !== '' ? $coupon['shortDescription'] : null,
            newsletterOptin: true,
        );
    }

    /**
     * A known e-mail (logged-in customer) is checked against the real
     * `newsletter` table; an anonymous visitor's e-mail is not known yet at
     * this point in the flow (it is only collected once they opt in), so
     * they default to not-subscribed, per the conception in MYO-475.
     */
    private function isAlreadySubscribed(ToolContext $context): bool
    {
        if ($context->customerId === null) {
            return false;
        }

        $email = (string) ($this->customerGateway->getProfile($context->customerId, $context->locale)['email'] ?? '');
        if ($email === '') {
            return false;
        }

        return $this->newsletterGateway->isSubscribed($email);
    }

    /**
     * @return array{code: string, title: string, shortDescription: string, discountLabel: string}|null
     */
    private function findWelcomeCoupon(ToolContext $context): ?array
    {
        foreach ($this->couponGateway->findApplicableCoupons($context) as $coupon) {
            if (WelcomeCouponScenarioResolver::looksLikeWelcomeOffer($coupon)) {
                return $coupon;
            }
        }

        return null;
    }
}
