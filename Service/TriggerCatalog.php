<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\CommerceAgents;
use Thelia\Core\Translation\Translator;

/**
 * The fixed list of event triggers a merchant can pick in the agent wizard
 * (plan MYO-227 §4.3, vocabulary agreed with the CTO in MYO-226 §3.1): always
 * business labels, never a raw Thelia event name on screen. Manual execution
 * is not part of this list — it is the "Run now" card action (§4.1), always
 * available and never a checkbox.
 */
final readonly class TriggerCatalog
{
    public const CART_ABANDONED = 'cart_abandoned';
    public const NEW_ORDER = 'new_order';
    public const ORDER_STATUS_CHANGE = 'order_status_change';
    public const NEW_CUSTOMER = 'new_customer';
    public const LOW_STOCK = 'low_stock';
    public const SCHEDULE = 'schedule';

    /** @var array<string, array{label: string, icon: string}> */
    private const DEFINITIONS = [
        self::CART_ABANDONED => ['label' => 'Abandoned cart', 'icon' => 'bi-cart-x'],
        self::NEW_ORDER => ['label' => 'New order', 'icon' => 'bi-bag-check'],
        self::ORDER_STATUS_CHANGE => ['label' => 'Order status change', 'icon' => 'bi-arrow-repeat'],
        self::NEW_CUSTOMER => ['label' => 'New customer', 'icon' => 'bi-person-plus'],
        self::LOW_STOCK => ['label' => 'Low stock', 'icon' => 'bi-exclamation-triangle'],
        self::SCHEDULE => ['label' => 'Schedule', 'icon' => 'bi-clock'],
    ];

    public function __construct(
        private Translator $translator,
    ) {
    }

    /**
     * @return list<array{code: string, label: string, icon: string}>
     */
    public function all(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $code => $definition) {
            $rows[] = [
                'code' => $code,
                'label' => $this->translator->trans($definition['label'], [], CommerceAgents::DOMAIN_NAME),
                'icon' => $definition['icon'],
            ];
        }

        return $rows;
    }

    public function label(string $code): string
    {
        return isset(self::DEFINITIONS[$code])
            ? $this->translator->trans(self::DEFINITIONS[$code]['label'], [], CommerceAgents::DOMAIN_NAME)
            : $code;
    }

    public function icon(string $code): string
    {
        return self::DEFINITIONS[$code]['icon'] ?? 'bi-lightning-charge';
    }
}
