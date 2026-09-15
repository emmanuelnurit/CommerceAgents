<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * One selectable model of the agent model selector: identity, reasoning tier
 * and price display, in the currency the provider publishes. This is the
 * data contract consumed by the back-office UI — keep it in sync with the
 * agent form.
 */
final readonly class ModelChoice
{
    public const TIER_FAST = 'fast';
    public const TIER_BALANCED = 'balanced';
    public const TIER_DEEP = 'deep';

    public const TIERS = [self::TIER_FAST, self::TIER_BALANCED, self::TIER_DEEP];

    /** Source strings of the i18n tier labels, in the module domain. */
    public const TIER_LABELS = [
        self::TIER_FAST => 'Fast',
        self::TIER_BALANCED => 'Balanced',
        self::TIER_DEEP => 'Deep thinking',
    ];

    public function __construct(
        public string $modelId,
        public string $name,
        public string $tier,
        public string $tierLabel,
        public string $priceInput,
        public string $priceOutput,
        public string $currency,
        public ?int $contextWindow,
        public bool $isDefault,
        public string $provider,
    ) {
    }
}
