<?php

declare(strict_types=1);

namespace CommerceAgents\Hook\Theme;

use CommerceAgents\Service\AgentConfigService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Taxation\TaxEngine\TaxEngine;
use Twig\Environment;

final readonly class ChatWidgetThemeHook implements ThemeHookInterface
{
    public function __construct(
        private AgentConfigService $configService,
        private CartFacade $cartFacade,
        private TaxEngine $taxEngine,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return $hookName === 'layout.body.bottom';
    }

    public function render(string $hookName, array $parameters): string
    {
        if (!$this->configService->isFrontChatEnabled()) {
            return '';
        }

        $session = $this->requestStack->getMainRequest()?->getSession();
        $theliaSession = $session instanceof Session ? $session : null;

        $cartItemCount = 0;
        $cartTotal = 0.0;
        $cart = $this->cartFacade->getCartFromSession();
        if ($cart !== null) {
            foreach ($cart->getCartItems() as $cartItem) {
                $cartItemCount += (int) $cartItem->getQuantity();
            }
            if ($cartItemCount > 0) {
                $cartTotal = round($cart->getTaxedAmount($this->taxEngine->getDeliveryCountry()), 2);
            }
        }

        $locale = $theliaSession?->getLang()->getLocale() ?? 'en_US';

        return $this->twig->render('@CommerceAgentsModule/theme-hook/chat_widget.html.twig', [
            'assistantName' => $this->configService->getAssistantName(),
            'cartItemCount' => $cartItemCount,
            'cartTotal' => $cartTotal,
            'currencyCode' => $theliaSession?->getCurrency()->getCode() ?? 'EUR',
            'checkoutUrl' => $this->urlGenerator->generate('checkout_cart'),
            'isCustomerLoggedIn' => $theliaSession?->getCustomerUser() !== null,
            'isFrench' => str_starts_with($locale, 'fr'),
        ]);
    }
}
