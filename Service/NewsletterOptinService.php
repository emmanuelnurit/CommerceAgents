<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Shopping\Gateway\CouponGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Newsletter\NewsletterEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Mailer\MailerFactory;
use Thelia\Model\ConfigQuery;

/**
 * MYO-471/MYO-475: subscribes a visitor's own e-mail to the newsletter and,
 * when a welcome coupon is configured, sends its code by e-mail — never in
 * the JSON response nor in the chat conversation.
 *
 * Correction to the ticket's original conception: Thelia core has no
 * double-opt-in token mechanism (no column for it on `newsletter`,
 * Action\Newsletter::subscribe() marks the row unsubscribed=0 synchronously),
 * so consent is captured up front here and the subscription is immediate —
 * the code being e-mail-only (never shown in the widget) is what stands in
 * for it at the business level. Action\Newsletter::subscribe() already
 * upserts by e-mail on its own (existing + unsubscribed row is
 * re-activated), so this always dispatches NEWSLETTER_SUBSCRIBE, mirroring
 * Thelia\Domain\Marketing\Service\NewsletterSubscriber for the anonymous
 * (no Customer) case.
 *
 * Shared by SubscribeToNewsletterTool (conversational tool call) and
 * ChatController::proactiveSubscribeNewsletter (the opt-in card's own
 * endpoint) so both paths validate and send identically.
 */
final readonly class NewsletterOptinService
{
    public const MESSAGE_CODE = 'commerceagents_newsletter_optin_coupon';

    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private CouponGatewayInterface $couponGateway,
        private MailerFactory $mailer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{subscribed: bool, error?: string}
     */
    public function subscribe(string $email, bool $consent, ToolContext $ctx): array
    {
        if ($consent !== true) {
            return ['subscribed' => false, 'error' => 'Consent is required'];
        }

        $email = trim($email);
        if ($email === '' || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            return ['subscribed' => false, 'error' => 'A valid email is required'];
        }

        $event = (new NewsletterEvent($email, $ctx->locale))->setFirstname('')->setLastname('');
        $this->eventDispatcher->dispatch($event, TheliaEvents::NEWSLETTER_SUBSCRIBE);

        $this->logger->info('[commerce-agents] newsletter opt-in subscribed', ['email' => $email]);

        $this->sendCouponEmailIfAny($email, $ctx);

        return ['subscribed' => true];
    }

    private function sendCouponEmailIfAny(string $email, ToolContext $ctx): void
    {
        $coupon = $this->findWelcomeCoupon($ctx);
        if ($coupon === null) {
            $this->logger->info('[commerce-agents] newsletter opt-in: no welcome coupon configured, subscription only', ['email' => $email]);

            return;
        }

        $this->mailer->sendEmailMessage(
            self::MESSAGE_CODE,
            [ConfigQuery::getStoreEmail() => ConfigQuery::getStoreName()],
            [$email => $email],
            [
                'coupon_code' => $coupon['code'],
                'discount_label' => $coupon['discountLabel'],
                'condition_label' => $coupon['shortDescription'],
            ],
            $ctx->locale,
        );

        $this->logger->info('[commerce-agents] newsletter opt-in coupon e-mailed', ['email' => $email, 'code' => $coupon['code']]);
    }

    /**
     * @return array{code: string, title: string, shortDescription: string, discountLabel: string}|null
     */
    private function findWelcomeCoupon(ToolContext $ctx): ?array
    {
        foreach ($this->couponGateway->findApplicableCoupons($ctx) as $coupon) {
            if (WelcomeCouponScenarioResolver::looksLikeWelcomeOffer($coupon)) {
                return $coupon;
            }
        }

        return null;
    }
}
