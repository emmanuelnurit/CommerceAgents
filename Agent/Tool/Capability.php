<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

/**
 * Capability groups a configurable agent can be granted. Each tool requires
 * exactly one of them; a tool whose capability is not granted to the agent is
 * never exposed to the LLM (plan MYO-226 §3.4).
 */
final class Capability
{
    public const CATALOG_READ = 'catalog.read';
    public const PRICING_WRITE = 'pricing.write';
    public const INVENTORY_WRITE = 'inventory.write';
    public const ORDERS_READ = 'orders.read';
    public const ANALYTICS_READ = 'analytics.read';
    public const CONTENT_READ = 'content.read';
    public const CART_WRITE = 'cart.write';
    public const CHECKOUT_WRITE = 'checkout.write';
    public const CUSTOMER_READ = 'customer.read';
    public const CHANNELS_SEND = 'channels.send';

    /**
     * Admin-side "who is this customer" lookup (MYO-286 item 1), kept
     * distinct from CUSTOMER_READ (the front tool exposing the logged-in
     * customer's own profile) and from ORDERS_READ so an agent can be
     * granted one without the other.
     */
    public const CUSTOMER_PROFILE_READ = 'customer.profile.read';

    /**
     * Applying an existing coupon to an existing order (MYO-286 item 3).
     * A write capability, so every grant of it is a StagedChange, never a
     * direct write.
     */
    public const ORDERS_WRITE = 'orders.write';

    /**
     * Reading product reviews from the "Comment" module (MYO-301 preset).
     * Only meaningful when that module is active; the preset offering this
     * capability is hidden otherwise.
     */
    public const REVIEWS_READ = 'reviews.read';

    /**
     * Drafting a reply to a product review (MYO-301 preset). A write
     * capability: the reply is always a StagedChange proposal, never
     * published directly.
     */
    public const REVIEWS_WRITE = 'reviews.write';

    public const ALL = [
        self::CATALOG_READ,
        self::PRICING_WRITE,
        self::INVENTORY_WRITE,
        self::ORDERS_READ,
        self::ANALYTICS_READ,
        self::CONTENT_READ,
        self::CART_WRITE,
        self::CHECKOUT_WRITE,
        self::CUSTOMER_READ,
        self::CHANNELS_SEND,
        self::CUSTOMER_PROFILE_READ,
        self::ORDERS_WRITE,
        self::REVIEWS_READ,
        self::REVIEWS_WRITE,
    ];

    private function __construct()
    {
    }
}
