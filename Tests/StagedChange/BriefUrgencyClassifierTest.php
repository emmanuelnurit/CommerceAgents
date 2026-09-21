<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\StagedChange;

use CommerceAgents\StagedChange\BriefUrgencyClassifier;
use PHPUnit\Framework\TestCase;

final class BriefUrgencyClassifierTest extends TestCase
{
    public function testStockChangesAreAlwaysNow(): void
    {
        self::assertSame(BriefUrgencyClassifier::NOW, BriefUrgencyClassifier::classify('pse_stock', null));
        self::assertSame(BriefUrgencyClassifier::NOW, BriefUrgencyClassifier::classify('pse_stock', 5));
    }

    public function testPriceChangesDefaultToWatch(): void
    {
        self::assertSame(BriefUrgencyClassifier::WATCH, BriefUrgencyClassifier::classify('pse_price', null));
    }

    public function testLowRatedReviewsAreNow(): void
    {
        self::assertSame(BriefUrgencyClassifier::NOW, BriefUrgencyClassifier::classify('review_reply', 1));
        self::assertSame(BriefUrgencyClassifier::NOW, BriefUrgencyClassifier::classify('review_reply', 2));
    }

    public function testHighlyRatedOrRatinglessReviewsAreWatch(): void
    {
        self::assertSame(BriefUrgencyClassifier::WATCH, BriefUrgencyClassifier::classify('review_reply', 3));
        self::assertSame(BriefUrgencyClassifier::WATCH, BriefUrgencyClassifier::classify('review_reply', 5));
        self::assertSame(BriefUrgencyClassifier::WATCH, BriefUrgencyClassifier::classify('review_reply', null));
    }

    public function testUnknownTargetTypesDefaultToWatch(): void
    {
        self::assertSame(BriefUrgencyClassifier::WATCH, BriefUrgencyClassifier::classify('order_coupon', null));
    }
}
