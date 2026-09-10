<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\BudgetStatus;
use PHPUnit\Framework\TestCase;

class BudgetStatusTest extends TestCase
{
    public function testNoBudgetMeansUnlimitedAndNeverBlocks(): void
    {
        $status = new BudgetStatus(budget: 0.0, spent: 12.5, warningPercent: 80, blockWhenExceeded: true);

        $this->assertSame(BudgetStatus::UNLIMITED, $status->state());
        $this->assertNull($status->percentUsed());
        $this->assertNull($status->remaining());
        $this->assertFalse($status->isBlocked());
    }

    public function testOkBelowWarningThreshold(): void
    {
        $status = new BudgetStatus(budget: 10.0, spent: 2.5, warningPercent: 80, blockWhenExceeded: true);

        $this->assertSame(BudgetStatus::OK, $status->state());
        $this->assertSame(25.0, $status->percentUsed());
        $this->assertSame(7.5, $status->remaining());
        $this->assertFalse($status->isBlocked());
    }

    public function testWarningFromThreshold(): void
    {
        $status = new BudgetStatus(budget: 10.0, spent: 8.0, warningPercent: 80, blockWhenExceeded: true);

        $this->assertSame(BudgetStatus::WARNING, $status->state());
        $this->assertFalse($status->isBlocked());
    }

    public function testExceededBlocksOnlyWhenAsked(): void
    {
        $blocking = new BudgetStatus(budget: 10.0, spent: 10.0, warningPercent: 80, blockWhenExceeded: true);
        $warnOnly = new BudgetStatus(budget: 10.0, spent: 11.0, warningPercent: 80, blockWhenExceeded: false);

        $this->assertSame(BudgetStatus::EXCEEDED, $blocking->state());
        $this->assertTrue($blocking->isBlocked());
        $this->assertSame(0.0, $blocking->remaining());

        $this->assertSame(BudgetStatus::EXCEEDED, $warnOnly->state());
        $this->assertFalse($warnOnly->isBlocked());
    }

    public function testWarningThresholdZeroDisablesWarning(): void
    {
        $status = new BudgetStatus(budget: 10.0, spent: 9.9, warningPercent: 0, blockWhenExceeded: true);

        $this->assertSame(BudgetStatus::OK, $status->state());
    }
}
