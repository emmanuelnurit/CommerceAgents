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
    public const STOCK_WATCH_RESTOCK = 'stock_watch_restock';
    public const CUSTOMER_REVIEWS_REPLY = 'customer_reviews_reply';
    public const FROM_SCRATCH = 'from_scratch';

    /**
     * @return array<string, array{
     *     code: string, title: string, subtitle: string, icon: string, color: string,
     *     rolePrompt: string, tier: string, triggers: list<array{type: string, hours?: int, time?: string}>,
     *     channel: ?string, capabilities: list<string>, requiresModule: ?string
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
                'channel' => 'mail',
                'capabilities' => ['catalog.read', 'customer.read'],
                'requiresModule' => null,
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
                'channel' => 'mail',
                'capabilities' => ['customer.read', 'catalog.read'],
                'requiresModule' => null,
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
                'channel' => 'mattermost',
                'capabilities' => ['analytics.read', 'orders.read'],
                'requiresModule' => null,
            ],
            self::STOCK_WATCH_RESTOCK => [
                'code' => self::STOCK_WATCH_RESTOCK,
                'title' => 'Stock watch & restock',
                'subtitle' => 'Flags stockouts and low stock, proposes restocks for your approval',
                'icon' => 'bi-box-seam',
                'color' => 'danger',
                'rolePrompt' => 'Tu surveilles les niveaux de stock de la boutique. Quand un produit passe en stock bas ou en rupture, tu le signales clairement (produit, quantité restante) et tu proposes une remise en stock avec la quantité avant/après. Tu ne modifies jamais un stock toi-même : chaque proposition attend une validation humaine dans les changements proposés.',
                'tier' => 'fast',
                'triggers' => [['type' => TriggerCatalog::LOW_STOCK, 'threshold' => 5]],
                'channel' => 'mail',
                'capabilities' => ['catalog.read', 'inventory.write'],
                'requiresModule' => null,
            ],
            self::CUSTOMER_REVIEWS_REPLY => [
                'code' => self::CUSTOMER_REVIEWS_REPLY,
                'title' => 'Customer reviews replies',
                'subtitle' => 'Drafts a reply to product reviews in your shop\'s tone — never published without your approval',
                'icon' => 'bi-chat-square-quote',
                'color' => 'primary',
                'rolePrompt' => 'Tu lis les avis clients laissés sur les fiches produits et tu rédiges un brouillon de réponse au ton de la boutique : remercie le client, réponds à ses remarques avec professionnalisme, reste bref. Tu ne publies jamais de réponse toi-même : chaque brouillon attend une validation humaine dans les changements proposés.',
                'tier' => 'balanced',
                'triggers' => [['type' => TriggerCatalog::SCHEDULE, 'time' => '10:00']],
                'channel' => null,
                'capabilities' => ['reviews.read', 'reviews.write'],
                'requiresModule' => 'Comment',
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
                'requiresModule' => null,
            ],
        ];
    }

    /**
     * @return ?array{
     *     code: string, title: string, subtitle: string, icon: string, color: string,
     *     rolePrompt: string, tier: string, triggers: list<array{type: string, hours?: int, time?: string}>,
     *     channel: ?string, capabilities: list<string>, requiresModule: ?string
     * }
     */
    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }
}
