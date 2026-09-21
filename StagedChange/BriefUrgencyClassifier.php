<?php

declare(strict_types=1);

namespace CommerceAgents\StagedChange;

/**
 * `agent_staged_change` has no urgency column (MYO-472 does not add one --
 * "pas de refonte du back"): this infers the Brief's "now" vs "watch" bucket
 * from what already exists. A stock change is time-sensitive by nature (a
 * stockout is a lost sale before anyone reopens the BO); a low-rated review
 * risks a public reply left hanging. Everything else defaults to "watch" so
 * "now" stays genuinely urgent instead of catching every pending item.
 */
final class BriefUrgencyClassifier
{
    public const NOW = 'now';
    public const WATCH = 'watch';

    private const LOW_RATING_THRESHOLD = 2;

    public static function classify(string $targetType, ?int $reviewRating): string
    {
        return match (true) {
            $targetType === 'pse_stock' => self::NOW,
            $targetType === 'review_reply' && $reviewRating !== null && $reviewRating <= self::LOW_RATING_THRESHOLD => self::NOW,
            default => self::WATCH,
        };
    }
}
