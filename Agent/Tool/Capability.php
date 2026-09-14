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
    ];

    private function __construct()
    {
    }
}
