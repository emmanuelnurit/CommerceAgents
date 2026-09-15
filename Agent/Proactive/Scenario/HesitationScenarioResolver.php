<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Proactive\Scenario;

use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveScenarioResolverInterface;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\ProactiveLocale;
use CommerceAgents\Tool\Shopping\Gateway\CatalogGatewayInterface;
use CommerceAgents\Tool\Shopping\Gateway\PolicyGatewayInterface;

/**
 * Plan MYO-236 scenario 3: the visitor spent too long on a product page, or
 * opened the cart repeatedly without checking out. Real stock comes from
 * CatalogGatewayInterface (the same gateway GetProductDetailsTool uses), and
 * the shipping/returns reassurance only appears when the store actually has
 * policy content configured — nothing here is invented.
 */
final readonly class HesitationScenarioResolver implements ProactiveScenarioResolverInterface
{
    public const SIGNAL_TYPE = 'hesitation';

    private const INTRO_WITH_STOCK = [
        'fr' => 'Vous hésitez encore ?',
        'en' => 'Still thinking it over?',
        'es' => '¿Aún lo está pensando?',
        'it' => 'Ci sta ancora pensando?',
    ];

    private const INTRO_GENERIC = [
        'fr' => 'Une question sur ce produit ?',
        'en' => 'Any question about this product?',
        'es' => '¿Alguna pregunta sobre este producto?',
        'it' => 'Una domanda su questo prodotto?',
    ];

    private const STOCK_FRAGMENT = [
        'fr' => 'Il reste %d en stock.',
        'en' => 'There are %d left in stock.',
        'es' => 'Quedan %d en stock.',
        'it' => 'Ne restano %d in stock.',
    ];

    private const SHIPPING_RETURNS_FRAGMENT = [
        'fr' => 'La livraison est suivie et les retours sont simples si besoin.',
        'en' => 'Shipping is tracked and returns are easy if needed.',
        'es' => 'El envío es seguido y las devoluciones son sencillas si es necesario.',
        'it' => 'La spedizione è tracciata e i resi sono semplici se necessario.',
    ];

    private const HELP_CLOSING = [
        'fr' => "N'hésitez pas à me poser vos questions.",
        'en' => 'Feel free to ask me your questions.',
        'es' => 'No dude en hacerme sus preguntas.',
        'it' => 'Non esiti a farmi le sue domande.',
    ];

    public function __construct(
        private CatalogGatewayInterface $catalogGateway,
        private PolicyGatewayInterface $policyGateway,
        private AssistantLocaleResolver $localeResolver,
    ) {
    }

    public function resolve(ProactiveSignal $signal, ToolContext $context): ?ProactiveMessage
    {
        if ($signal->type !== self::SIGNAL_TYPE) {
            return null;
        }

        $stock = $this->realStock($signal->context['product_id'] ?? null, $context);
        $hasPolicies = $this->policyGateway->getPolicies($context->locale) !== [];
        $group = ProactiveLocale::group($context->locale, $this->localeResolver->siteDefault());

        return new ProactiveMessage($this->compose($stock, $hasPolicies, $group));
    }

    private function realStock(mixed $rawProductId, ToolContext $context): ?int
    {
        if (!\is_int($rawProductId) && !\is_string($rawProductId)) {
            return null;
        }

        $productId = (int) $rawProductId;
        if ($productId <= 0) {
            return null;
        }

        $product = $this->catalogGateway->getProductDetails($productId, $context);
        if ($product === null) {
            return null;
        }

        $pses = $product['pses'] ?? [];
        if ($pses === []) {
            return null;
        }

        $total = 0;
        foreach ($pses as $pse) {
            $total += max(0, (int) ($pse['stock'] ?? 0));
        }

        return $total;
    }

    private function compose(?int $stock, bool $hasPolicies, string $group): string
    {
        $parts = [$stock !== null ? self::INTRO_WITH_STOCK[$group] : self::INTRO_GENERIC[$group]];

        if ($stock !== null) {
            $parts[] = \sprintf(self::STOCK_FRAGMENT[$group], $stock);
        }

        if ($hasPolicies) {
            $parts[] = self::SHIPPING_RETURNS_FRAGMENT[$group];
        } elseif ($stock === null) {
            $parts[] = self::HELP_CLOSING[$group];
        }

        return implode(' ', $parts);
    }
}
