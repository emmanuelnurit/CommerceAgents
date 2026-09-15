<?php

declare(strict_types=1);

namespace CommerceAgents\Hook\Theme;

use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\Shopping\AccountSummaryProvider;
use CommerceAgents\Service\Shopping\TheliaCartGateway;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;
use Twig\Environment;

final readonly class ChatWidgetThemeHook implements ThemeHookInterface
{
    /**
     * module_asset() serves the published copy under a stable URL and nginx sends
     * no Cache-Control, so a browser keeps yesterday's widget until a hard
     * refresh. Stamp the URLs with the source mtimes instead.
     */
    private const VERSIONED_ASSETS = [
        'assets/css/chat-widget.css',
        'assets/js/chat-markdown.js',
        'assets/js/chat-widget.js',
    ];

    public function __construct(
        private AgentConfigService $configService,
        private TheliaCartGateway $cartGateway,
        private AccountSummaryProvider $accountSummaryProvider,
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

        $customerId = $theliaSession?->getCustomerUser()?->getId();
        $account = $customerId !== null
            ? $this->accountSummaryProvider->forCustomer($customerId, $locale)
            : $this->accountSummaryProvider->forAnonymous();

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
                'variantsTitle' => $translate('Available options'),
                'optionResults' => $translate('Variants matching'),
                'expandAssistant' => $translate('Open the full conversation'),
                'minimiseAssistant' => $translate('Minimise the conversation'),
                'addToCartPrompt' => $translate('Add this product to my cart:'),
                'subtitle' => $translate('Shopping assistant powered by AI'),
                'accountTitle' => $translate('My account'),
                'accountHint' => $translate('Create an account to track your orders and speed up checkout.'),
                'createAccount' => $translate('Create my account'),
                'login' => $translate('Log in'),
                'myAccount' => $translate('Go to my account'),
                'noOrdersYet' => $translate('No orders yet.'),
                'viewAllOrders' => $translate('View all my orders'),
            ],
            'assetVersion' => self::assetVersion(),
            'cart' => $this->cartGateway->snapshot(),
            'account' => $account,
            'locale' => $locale,
            'checkoutUrl' => $this->urlGenerator->generate('checkout_cart'),
            'isCustomerLoggedIn' => $account['loggedIn'],
        ]);
    }

    private static function assetVersion(): string
    {
        $latest = 0;

        foreach (self::VERSIONED_ASSETS as $asset) {
            $path = \dirname(__DIR__, 2).'/templates/frontOffice/default/'.$asset;
            $mtime = is_file($path) ? filemtime($path) : false;
            if ($mtime !== false) {
                $latest = max($latest, $mtime);
            }
        }

        return $latest === 0 ? '0' : dechex($latest);
    }
}
