<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * The 4 "agent models" (presets) offered by the empty state and wizard step 1
 * (plan MYO-227 §4.2). A preset only pre-fills the wizard fields — it is a
 * static PHP array, never persisted and never a schema concept of its own
 * (§7.3 of the spec: "aucun impact schéma").
 */
final class AgentPresets
{
    public const CART_ABANDONED = 'cart_abandoned_relaunch';
    public const WELCOME_NEW_CUSTOMER = 'welcome_new_customer';
    public const DAILY_SALES_SUMMARY = 'daily_sales_summary';
    public const FROM_SCRATCH = 'from_scratch';

    /**
     * @return array<string, array{
     *     code: string, title: string, subtitle: string, icon: string, color: string,
     *     rolePrompt: string, tier: string, triggers: list<array{type: string, hours?: int, time?: string}>,
     *     channel: ?string, capabilities: list<string>
     * }>
     */
    public static function all(): array
    {
        return [
            self::CART_ABANDONED => [
                'code' => self::CART_ABANDONED,
                'title' => 'Abandoned cart relaunch',
                'subtitle' => 'Gentle e-mail reminder 24 h after the cart was abandoned',
                'icon' => 'bi-cart-x',
                'color' => 'warning',
                'rolePrompt' => 'Tu aides la boutique à récupérer les paniers abandonnés. Quand un client laisse un panier plus de 24 heures, écris-lui un e-mail chaleureux et bref qui lui rappelle son panier, sans le presser. Ne propose jamais de remise sans mon accord.',
                'tier' => 'fast',
                'triggers' => [['type' => TriggerCatalog::CART_ABANDONED, 'hours' => 24]],
                'channel' => 'email',
                'capabilities' => ['catalog.read', 'customer.read'],
            ],
            self::WELCOME_NEW_CUSTOMER => [
                'code' => self::WELCOME_NEW_CUSTOMER,
                'title' => 'Welcome new customers',
                'subtitle' => 'Personalised welcome message on every sign-up',
                'icon' => 'bi-person-plus',
                'color' => 'success',
                'rolePrompt' => 'Tu accueilles chaque nouveau client de la boutique. Écris-lui un e-mail de bienvenue chaleureux et court, présente la boutique en deux mots, et donne-lui envie de découvrir le catalogue.',
                'tier' => 'fast',
                'triggers' => [['type' => TriggerCatalog::NEW_CUSTOMER]],
                'channel' => 'email',
                'capabilities' => ['customer.read', 'catalog.read'],
            ],
            self::DAILY_SALES_SUMMARY => [
                'code' => self::DAILY_SALES_SUMMARY,
                'title' => 'Daily sales summary',
                'subtitle' => "Today's report on Slack, Mattermost or e-mail",
                'icon' => 'bi-graph-up',
                'color' => 'info',
                'rolePrompt' => "Chaque soir, tu résumes la journée de vente de la boutique en quelques lignes claires : chiffre d'affaires, nombre de commandes, produit qui se vend le mieux. Reste factuel et concis.",
                'tier' => 'balanced',
                'triggers' => [['type' => TriggerCatalog::SCHEDULE, 'time' => '19:00']],
                'channel' => 'webhook',
                'capabilities' => ['analytics.read', 'orders.read'],
            ],
            self::FROM_SCRATCH => [
                'code' => self::FROM_SCRATCH,
                'title' => 'Start from scratch',
                'subtitle' => 'Freely define the role, triggers and channels',
                'icon' => 'bi-sliders',
                'color' => 'secondary',
                'rolePrompt' => '',
                'tier' => 'balanced',
                'triggers' => [],
                'channel' => null,
                'capabilities' => [],
            ],
        ];
    }

    /**
     * @return ?array{
     *     code: string, title: string, subtitle: string, icon: string, color: string,
     *     rolePrompt: string, tier: string, triggers: list<array{type: string, hours?: int, time?: string}>,
     *     channel: ?string, capabilities: list<string>
     * }
     */
    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }
}
