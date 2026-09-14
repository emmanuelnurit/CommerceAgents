<?php

declare(strict_types=1);

namespace CommerceAgents\Hook\Theme;

use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\Shopping\TheliaCartGateway;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;
use Twig\Environment;

final readonly class ChatWidgetThemeHook implements ThemeHookInterface
{
    public function __construct(
        private AgentConfigService $configService,
        private TheliaCartGateway $cartGateway,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        private Translator $translator,
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

        // The Twig |trans filter resolves against the Symfony translator, which
        // never carries module catalogues on front requests (the BO bundle only
        // bridges module domains under /admin). Translate here with the Thelia
        // translator, which holds the module I18n files and the session locale.
        $locale = $theliaSession?->getLang()->getLocale() ?? 'en_US';
        $translate = fn (string $id): string => $this->translator->trans($id, [], 'commerceagents', $locale);

        $assistantName = $this->configService->getAssistantName();

        return $this->twig->render('@CommerceAgentsModule/theme-hook/chat_widget.html.twig', [
            'assistantName' => $assistantName,
            'i18n' => [
                'itemsLabel' => $translate('item(s)'),
                'connectionLost' => $translate('Connection lost'),
                'serviceUnavailable' => $translate('Service unavailable'),
                'error' => $translate('Something went wrong'),
                'completeOrder' => $translate('Complete my order'),
                'helpTitle' => $translate('How can I help you?'),
                'suggestProduct' => $translate('I am looking for a product…'),
                'suggestCart' => $translate('What is in my cart?'),
                'suggestShipping' => $translate('What are your shipping conditions?'),
                'suggestOrders' => $translate('Where are my orders?'),
                'askPlaceholder' => $translate('Ask me for a product, your cart, a delivery…'),
                'openAssistant' => $translate('Open the shopping assistant'),
                'closeAssistant' => $translate('Close the assistant'),
                'clearConversation' => $translate('Start a new conversation'),
                'send' => $translate('Send'),
                'yourCart' => $translate('Your cart'),
                'cartEmpty' => $translate('Your cart is empty for now.'),
                'otherLines' => $translate('other line(s)'),
                'total' => $translate('Total'),
                'featured' => $translate('Best match'),
                'highlightsTitle' => $translate('Products discussed'),
                'suggestionsTitle' => $translate('Keep going'),
                'addToCart' => $translate('Add to cart'),
                'viewProduct' => $translate('View product'),
                'inStock' => $translate('In stock'),
                'outOfStock' => $translate('Out of stock'),
                'inCategory' => $translate('In the category'),
                'expandAssistant' => $translate('Open the full conversation'),
                'minimiseAssistant' => $translate('Minimise the conversation'),
                'addToCartPrompt' => $translate('Add this product to my cart:'),
                'subtitle' => $translate('Shopping assistant powered by AI'),
            ],
            'cart' => $this->cartGateway->snapshot(),
            'locale' => $locale,
            'checkoutUrl' => $this->urlGenerator->generate('checkout_cart'),
            'isCustomerLoggedIn' => $theliaSession?->getCustomerUser() !== null,
        ]);
    }
}
