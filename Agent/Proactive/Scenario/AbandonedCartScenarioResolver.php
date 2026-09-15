<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Proactive\Scenario;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\ProactiveLocale;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\PolicyGatewayInterface;

/**
 * Plan MYO-236 scenario 4: the cart has been sitting non-empty and idle
 * since the last addition, within the same session. The cart is read live
 * from CartGatewayInterface — if it is empty by the time this resolves
 * (removed, or already checked out), there is nothing to reassure the
 * visitor about, and no message is sent.
 *
 * No coupon code is attached here: "un coupon panier abandonné" needs the
 * eligibility check the coupon tool of MYO-247 (Lot 2, in progress alongside
 * this lot) delivers. Wiring the promo-code half of scenario 4 to that tool
 * is left to whichever lot lands it, rather than re-deriving Thelia's coupon
 * condition matching here and risking two divergent implementations.
 */
final readonly class AbandonedCartScenarioResolver implements ProactiveScenarioResolverInterface
{
    public const SIGNAL_TYPE = 'cart_abandoned_session';

    private const CART_WAITING = [
        'fr' => 'Votre panier vous attend toujours (%d article(s)).',
        'en' => 'Your cart is still waiting for you (%d item(s)).',
        'es' => 'Su carrito le sigue esperando (%d artículo(s)).',
        'it' => 'Il suo carrello la sta ancora aspettando (%d articolo/i).',
    ];

    private const SHIPPING_RETURNS_FRAGMENT = [
        'fr' => 'La livraison est suivie et les retours sont simples si besoin.',
        'en' => 'Shipping is tracked and returns are easy if needed.',
        'es' => 'El envío es seguido y las devoluciones son sencillas si es necesario.',
        'it' => 'La spedizione è tracciata e i resi sono semplici se necessario.',
    ];

    private const CHECKOUT_INVITE = [
        'fr' => 'Vous pouvez finaliser votre commande quand vous voulez.',
        'en' => "You can complete your order whenever you're ready.",
        'es' => 'Puede finalizar su pedido cuando quiera.',
        'it' => "Può completare l'ordine quando vuole.",
    ];

    public function __construct(
        private CartGatewayInterface $cartGateway,
        private PolicyGatewayInterface $policyGateway,
    ) {
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        if ($signal->type !== self::SIGNAL_TYPE) {
            return null;
        }

        $cart = $this->cartGateway->getCart($context);
        $itemCount = (int) ($cart['itemCount'] ?? 0);
        if ($itemCount <= 0) {
            return null;
        }

        $hasPolicies = $this->policyGateway->getPolicies($context->locale) !== [];

        return new ProactiveMessage($this->compose($itemCount, $hasPolicies, ProactiveLocale::group($context->locale)));
    }

    private function compose(int $itemCount, bool $hasPolicies, string $group): string
    {
        $parts = [\sprintf(self::CART_WAITING[$group], $itemCount)];

        if ($hasPolicies) {
            $parts[] = self::SHIPPING_RETURNS_FRAGMENT[$group];
        }

        $parts[] = self::CHECKOUT_INVITE[$group];

        return implode(' ', $parts);
    }
}
