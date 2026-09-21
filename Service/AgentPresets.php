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
    public const STUCK_ORDERS_WATCH = 'stuck_orders_watch';
    public const PRODUCT_SHEET_AUDIT = 'product_sheet_audit';
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
                'capabilities' => ['catalog.read', 'customer.read', 'customer.profile.read'],
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
                'capabilities' => ['customer.read', 'catalog.read', 'customer.profile.read'],
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
                'rolePrompt' => 'Tu surveilles les niveaux de stock de la boutique. Quand un produit passe en stock bas ou en rupture, tu le signales clairement (produit, quantité restante), tu calcules sa vélocité de vente avec get_sales_velocity, tu vérifies avec get_campaigns s\'il est en campagne active, puis tu proposes un réassort chiffré avec propose_restock (quantité restante, vélocité observée, date de rupture estimée, quantité proposée). Tu ne modifies jamais un stock toi-même : chaque proposition attend une validation humaine dans les changements proposés.',
                'tier' => 'fast',
                'triggers' => [['type' => TriggerCatalog::LOW_STOCK, 'threshold' => 5]],
                'channel' => 'mail',
                'capabilities' => ['catalog.read', 'inventory.write', 'analytics.read'],
                'requiresModule' => null,
            ],
            self::CUSTOMER_REVIEWS_REPLY => [
                'code' => self::CUSTOMER_REVIEWS_REPLY,
                'title' => 'Customer reviews replies',
                'subtitle' => 'Drafts a reply to product reviews in your shop\'s tone — kept for your internal review, never published automatically',
                'icon' => 'bi-chat-square-quote',
                'color' => 'primary',
                'rolePrompt' => 'Tu lis les avis clients laissés sur les fiches produits et tu rédiges un brouillon de réponse au ton de la boutique : remercie le client, réponds à ses remarques avec professionnalisme, reste bref. Tu ne publies jamais de réponse toi-même : chaque brouillon attend une validation humaine dans les changements proposés.',
                'tier' => 'balanced',
                'triggers' => [['type' => TriggerCatalog::SCHEDULE, 'time' => '10:00']],
                'channel' => null,
                'capabilities' => ['reviews.read', 'reviews.write'],
                'requiresModule' => 'Comment',
            ],
            self::STUCK_ORDERS_WATCH => [
                'code' => self::STUCK_ORDERS_WATCH,
                'title' => 'Stuck orders',
                'subtitle' => 'Flags orders stuck in the same status for too long and drafts a follow-up e-mail to the customer',
                'icon' => 'bi-hourglass-split',
                'color' => 'warning',
                'rolePrompt' => "Chaque jour, tu passes en revue les commandes récentes de la boutique et tu repères celles qui semblent bloquées (aucune évolution de statut depuis plusieurs jours, alors qu'une commande similaire aurait déjà avancé). Pour chaque commande bloquée que tu identifies, rédige un e-mail bref et rassurant au client pour lui donner des nouvelles. Tu n'envoies jamais cet e-mail toi-même : chaque brouillon attend une validation humaine dans les changements proposés.",
                'tier' => 'fast',
                'triggers' => [['type' => TriggerCatalog::SCHEDULE, 'time' => '09:00']],
                'channel' => 'mail',
                'capabilities' => ['orders.read', 'customer.read', 'customer.profile.read'],
                'requiresModule' => null,
            ],
            self::PRODUCT_SHEET_AUDIT => [
                'code' => self::PRODUCT_SHEET_AUDIT,
                'title' => 'Product sheet audit',
                'subtitle' => 'Reviews product sheets for missing or inconsistent content and reports findings to the merchant',
                'icon' => 'bi-card-checklist',
                'color' => 'info',
                'rolePrompt' => "Chaque semaine, tu relis un échantillon de fiches produits de la boutique (descriptions, informations manquantes ou incohérentes) et tu envoies au marchand un compte-rendu clair des fiches à améliorer en priorité, avec pour chacune le problème repéré. Tu ne modifies jamais une fiche toi-même : tu te contentes de signaler.",
                'tier' => 'balanced',
                'triggers' => [['type' => TriggerCatalog::SCHEDULE, 'time' => '08:00']],
                'channel' => 'mail',
                'capabilities' => ['catalog.read', 'content.read'],
                'requiresModule' => null,
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
