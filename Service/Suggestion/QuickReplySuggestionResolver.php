<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Suggestion;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Shopping\AccountSummaryProvider;
use CommerceAgents\Tool\Shopping\Gateway\CartGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\CategoryGatewayInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Translation\Translator;

/**
 * Resolves up to 3 contextual quick replies for the chat front widget
 * (MYO-282 §1, MYO-283). Purely deterministic: the fallback rules named in
 * the CEO arbitrage (no dedicated LLM call for this), keyed off the last
 * tool call executed during the turn and, failing that, keywords in the
 * visitor's message. Every navigation suggestion is resolved against a real
 * route or a real category this same call — never a guessed or cached URL
 * (zero suggestion fantôme, lesson MYO-263).
 */
final readonly class QuickReplySuggestionResolver
{
    private const MAX_SUGGESTIONS = 3;

    private const ACCOUNT_KEYWORDS = [
        'compte', 'commande', 'commandes', 'adresse',
        'account', 'order', 'orders', 'address',
        'cuenta', 'pedido', 'pedidos', 'dirección', 'direccion',
        'ordine', 'ordini', 'indirizzo',
    ];

    private const CART_KEYWORDS = [
        'panier', 'livraison', 'expédition', 'expedition', 'frais de port',
        'cart', 'shipping', 'delivery',
        'carrito', 'envío', 'envio',
        'carrello', 'spedizione',
    ];

    private const SUPPORT_KEYWORDS = [
        'retour', 'remboursement', 'réclamation', 'reclamation', 'problème', 'probleme',
        'return', 'refund', 'complaint', 'problem',
        'devolución', 'devolucion', 'reembolso', 'problema',
        'reso', 'rimborso',
    ];

    public function __construct(
        private CategoryGatewayInterface $categoryGateway,
        private CatalogGatewayInterface $catalogGateway,
        private CartGatewayInterface $cartGateway,
        private AccountSummaryProvider $accountSummaryProvider,
        private UrlGeneratorInterface $urlGenerator,
        private Translator $translator,
    ) {
    }

    /**
     * @param list<array{name: string, result: array}> $toolCalls tool calls executed during this turn, in order
     *
     * @return array{suggestions: list<Suggestion>, isDefaultIntent: bool}
     */
    public function resolve(ToolContext $ctx, array $toolCalls, string $userMessage): array
    {
        $last = self::lastToolCall($toolCalls);
        $intent = $this->detectIntent($last, $userMessage);

        $suggestions = match ($intent) {
            'catalog' => $this->catalogSuggestions($last, $ctx),
            'account' => $this->accountSuggestions($ctx),
            'cart' => $this->cartSuggestions($ctx),
            'support' => $this->supportSuggestions($last, $ctx->locale),
            default => $this->defaultSuggestions($ctx),
        };

        return [
            'suggestions' => \array_slice($suggestions, 0, self::MAX_SUGGESTIONS),
            'isDefaultIntent' => 'default' === $intent,
        ];
    }

    /**
     * @param array{name: string, result: array}|null $last
     */
    private function detectIntent(?array $last, string $userMessage): string
    {
        if ($last !== null) {
            if ('search_products' === $last['name'] && (!empty($last['result']['products']) || !empty($last['result']['variants']))) {
                return 'catalog';
            }
            if ('get_product_details' === $last['name'] && isset($last['result']['product'])) {
                return 'catalog';
            }
            if (\in_array($last['name'], ['get_my_profile', 'get_orders'], true)) {
                return 'account';
            }
            if (\in_array($last['name'], ['add_to_cart', 'get_cart'], true)) {
                return 'cart';
            }
        }

        $message = mb_strtolower($userMessage);
        if (self::containsAny($message, self::ACCOUNT_KEYWORDS)) {
            return 'account';
        }
        if (self::containsAny($message, self::CART_KEYWORDS)) {
            return 'cart';
        }
        if (self::containsAny($message, self::SUPPORT_KEYWORDS) || self::contactPageUrl($last) !== null) {
            return 'support';
        }

        return 'default';
    }

    /**
     * @param array{name: string, result: array}|null $last
     *
     * @return list<Suggestion>
     */
    private function catalogSuggestions(?array $last, ToolContext $ctx): array
    {
        if ($last === null) {
            return [];
        }

        $categoryId = $this->categoryIdFromToolCall($last);
        if ($categoryId === null) {
            return [];
        }

        $sibling = $this->categoryGateway->getSiblings($categoryId, $ctx->locale, 1)[0] ?? null;
        if ($sibling === null || $sibling['url'] === null) {
            return [];
        }

        return [
            Suggestion::navigate(
                'cat-'.$sibling['id'],
                $this->translator->trans('See also %category%', ['%category%' => $sibling['title']], 'commerceagents', $ctx->locale),
                $sibling['url'],
            ),
        ];
    }

    /**
     * @param array{name: string, result: array} $last
     */
    private function categoryIdFromToolCall(array $last): ?int
    {
        if ('search_products' === $last['name']) {
            $matchedCategory = $last['result']['matched_category'] ?? null;
            if (\is_array($matchedCategory) && isset($matchedCategory['id'])) {
                return (int) $matchedCategory['id'];
            }

            $firstProduct = $last['result']['products'][0] ?? null;
            if (\is_array($firstProduct) && isset($firstProduct['id'])) {
                return $this->catalogGateway->getDefaultCategoryId((int) $firstProduct['id']);
            }

            return null;
        }

        if ('get_product_details' === $last['name']) {
            $product = $last['result']['product'] ?? null;
            if (\is_array($product) && isset($product['id'])) {
                return $this->catalogGateway->getDefaultCategoryId((int) $product['id']);
            }
        }

        return null;
    }

    /**
     * @return list<Suggestion>
     */
    private function accountSuggestions(ToolContext $ctx): array
    {
        if ($ctx->customerId === null) {
            $account = $this->accountSummaryProvider->forAnonymous();

            return [
                Suggestion::navigate('login', $this->translator->trans('Log in', [], 'commerceagents', $ctx->locale), $account['loginUrl']),
                Suggestion::navigate('register', $this->translator->trans('Create my account', [], 'commerceagents', $ctx->locale), $account['registerUrl']),
            ];
        }

        $account = $this->accountSummaryProvider->forCustomer($ctx->customerId, $ctx->locale);

        return [
            Suggestion::navigate('edit-address', $this->translator->trans('Edit my address', [], 'commerceagents', $ctx->locale), $account['accountUrl']),
            Suggestion::navigate('view-orders', $this->translator->trans('View all my orders', [], 'commerceagents', $ctx->locale), $account['ordersUrl']),
            Suggestion::navigate('my-account', $this->translator->trans('Go to my account', [], 'commerceagents', $ctx->locale), $account['accountUrl']),
        ];
    }

    /**
     * @return list<Suggestion>
     */
    private function cartSuggestions(ToolContext $ctx): array
    {
        $cartUrl = $this->urlGenerator->generate('checkout_cart');
        $cart = $this->cartGateway->getCart($ctx);

        $suggestions = [
            Suggestion::navigate('view-cart', $this->translator->trans('Your cart', [], 'commerceagents', $ctx->locale), $cartUrl),
        ];

        if (($cart['itemCount'] ?? 0) > 0) {
            $suggestions[] = Suggestion::navigate('complete-order', $this->translator->trans('Complete my order', [], 'commerceagents', $ctx->locale), $cartUrl);
        }

        $shippingLabel = $this->translator->trans('What are your shipping conditions?', [], 'commerceagents', $ctx->locale);
        $suggestions[] = Suggestion::message('shipping-conditions', $shippingLabel, $shippingLabel);

        return $suggestions;
    }

    /**
     * @param array{name: string, result: array}|null $last
     *
     * @return list<Suggestion>
     */
    private function supportSuggestions(?array $last, string $locale): array
    {
        $returnLabel = $this->translator->trans('Return policy', [], 'commerceagents', $locale);
        $suggestions = [Suggestion::message('return-policy', $returnLabel, $returnLabel)];

        $contactUrl = self::contactPageUrl($last);
        if ($contactUrl !== null) {
            $suggestions[] = Suggestion::navigate(
                'contact-support',
                $this->translator->trans('Contact customer service', [], 'commerceagents', $locale),
                $contactUrl,
            );
        }

        return $suggestions;
    }

    /**
     * @param array{name: string, result: array}|null $last
     */
    private static function contactPageUrl(?array $last): ?string
    {
        if ($last === null || 'get_site_pages' !== $last['name']) {
            return null;
        }

        foreach ($last['result']['pages'] ?? [] as $page) {
            if (\is_array($page) && isset($page['title'], $page['url']) && str_contains(mb_strtolower((string) $page['title']), 'contact')) {
                return (string) $page['url'];
            }
        }

        return null;
    }

    /**
     * Reuses the exact hero prompts (already real, already translated) rather
     * than inventing new labels for the "nothing detected yet" state.
     *
     * @return list<Suggestion>
     */
    private function defaultSuggestions(ToolContext $ctx): array
    {
        $labels = [
            'default-product' => $this->translator->trans('I am looking for a product…', [], 'commerceagents', $ctx->locale),
            'default-cart' => $this->translator->trans('What is in my cart?', [], 'commerceagents', $ctx->locale),
            'default-shipping' => $this->translator->trans('What are your shipping conditions?', [], 'commerceagents', $ctx->locale),
        ];
        if ($ctx->customerId !== null) {
            $labels['default-orders'] = $this->translator->trans('Where are my orders?', [], 'commerceagents', $ctx->locale);
        }

        return array_map(
            static fn (string $id, string $label): Suggestion => Suggestion::message($id, $label, $label),
            array_keys($labels),
            array_values($labels),
        );
    }

    /**
     * @param list<array{name: string, result: array}> $toolCalls
     *
     * @return array{name: string, result: array}|null
     */
    private static function lastToolCall(array $toolCalls): ?array
    {
        return $toolCalls === [] ? null : $toolCalls[array_key_last($toolCalls)];
    }

    /**
     * @param list<string> $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
