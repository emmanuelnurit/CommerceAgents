<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\CommerceAgents;
use Thelia\Core\Translation\Translator;

/**
 * Business-facing labels of the agent capabilities (plan MYO-227 §2: never a
 * raw tool/capability name on screen) and which ones mutate shop data, hence
 * require the "Changements proposés" mention (wizard step 4, spec §4.3).
 */
final readonly class CapabilityCatalog
{
    public const GROUP_READ = 'read';
    public const GROUP_WRITE = 'write';

    /** @var array<string, array{label: string, group: string, stagedChange: bool}> */
    private const DEFINITIONS = [
        Capability::CATALOG_READ => ['label' => 'Read the catalog', 'group' => self::GROUP_READ, 'stagedChange' => false],
        Capability::CONTENT_READ => ['label' => 'Read content pages', 'group' => self::GROUP_READ, 'stagedChange' => false],
        Capability::CUSTOMER_READ => ['label' => 'Read customers', 'group' => self::GROUP_READ, 'stagedChange' => false],
        Capability::ORDERS_READ => ['label' => 'Read orders', 'group' => self::GROUP_READ, 'stagedChange' => false],
        Capability::ANALYTICS_READ => ['label' => 'Read analytics', 'group' => self::GROUP_READ, 'stagedChange' => false],
        Capability::CUSTOMER_PROFILE_READ => ['label' => 'Read a given customer\'s profile', 'group' => self::GROUP_READ, 'stagedChange' => false],
        Capability::PRICING_WRITE => ['label' => 'Change prices', 'group' => self::GROUP_WRITE, 'stagedChange' => true],
        Capability::INVENTORY_WRITE => ['label' => 'Change stock levels', 'group' => self::GROUP_WRITE, 'stagedChange' => true],
        Capability::CART_WRITE => ['label' => 'Modify visitor carts', 'group' => self::GROUP_WRITE, 'stagedChange' => true],
        Capability::CHECKOUT_WRITE => ['label' => 'Place orders on behalf of a visitor', 'group' => self::GROUP_WRITE, 'stagedChange' => true],
        Capability::CHANNELS_SEND => ['label' => 'Send messages on its channels', 'group' => self::GROUP_WRITE, 'stagedChange' => false],
        Capability::ORDERS_WRITE => ['label' => 'Apply an existing coupon to an order', 'group' => self::GROUP_WRITE, 'stagedChange' => true],
        Capability::REVIEWS_READ => ['label' => 'Read product reviews', 'group' => self::GROUP_READ, 'stagedChange' => false],
        Capability::REVIEWS_WRITE => ['label' => 'Draft a reply to a product review', 'group' => self::GROUP_WRITE, 'stagedChange' => true],
    ];

    public function __construct(
        private Translator $translator,
    ) {
    }

    /**
     * @return list<array{code: string, label: string, group: string, stagedChange: bool}>
     */
    public function all(): array
    {
        $rows = [];
        foreach (self::DEFINITIONS as $code => $definition) {
            $rows[] = [
                'code' => $code,
                'label' => $this->translator->trans($definition['label'], [], CommerceAgents::DOMAIN_NAME),
                'group' => $definition['group'],
                'stagedChange' => $definition['stagedChange'],
            ];
        }

        return $rows;
    }

    public function isStagedChange(string $capability): bool
    {
        return self::DEFINITIONS[$capability]['stagedChange'] ?? false;
    }
}
