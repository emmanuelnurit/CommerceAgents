<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Shopping;

use CommerceAgents\Service\Shopping\PriceRange;
use PHPUnit\Framework\TestCase;

class PriceRangeTest extends TestCase
{
    public function testASaneRangeIsLeftAlone(): void
    {
        $this->assertSame([10.0, 200.0], PriceRange::sane(10.0, 200.0));
    }

    public function testASingleBoundIsLeftAlone(): void
    {
        $this->assertSame([null, 200.0], PriceRange::sane(null, 200.0));
        $this->assertSame([200.0, null], PriceRange::sane(200.0, null));
        $this->assertSame([null, null], PriceRange::sane(null, null));
    }

    public function testInvertedBoundsArePutBackInOrder(): void
    {
        $this->assertSame([50.0, 900.0], PriceRange::sane(900.0, 50.0));
    }

    public function testAnExactPriceBecomesACeiling(): void
    {
        // "min 200, max 200" is a model slip, and it can only ever return nothing.
        $this->assertSame([null, 200.0], PriceRange::sane(200.0, 200.0));
    }

    public function testZeroIsNotMistakenForAMissingBound(): void
    {
        $this->assertSame([0.0, 200.0], PriceRange::sane(0.0, 200.0));
    }
}
