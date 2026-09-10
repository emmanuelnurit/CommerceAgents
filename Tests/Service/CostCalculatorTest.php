<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\CostCalculator;
use PHPUnit\Framework\TestCase;

class CostCalculatorTest extends TestCase
{
    public function testCostFromPricesPerMillionTokens(): void
    {
        // Claude Sonnet 5: $2 in, $10 out per million tokens.
        $this->assertSame(0.004272, CostCalculator::cost(2.0, 10.0, 2116, 4));
        $this->assertSame(0.0, CostCalculator::cost(2.0, 10.0, 0, 0));
    }

    public function testUnknownPriceGivesNull(): void
    {
        $this->assertNull(CostCalculator::cost(null, 10.0, 100, 10));
        $this->assertNull(CostCalculator::cost(2.0, null, 100, 10));
    }
}
