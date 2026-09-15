<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\Service\Run\TriggerConditions;
use PHPUnit\Framework\TestCase;

class TriggerConditionsTest extends TestCase
{
    public function testNullConditionsAlwaysMatch(): void
    {
        self::assertTrue(TriggerConditions::matches(null, ['amount' => 1.0, 'status_id' => 3]));
    }

    public function testEmptyConditionsAlwaysMatch(): void
    {
        self::assertTrue(TriggerConditions::matches('', ['amount' => 1.0]));
    }

    public function testMalformedJsonIsTreatedAsNoCondition(): void
    {
        self::assertTrue(TriggerConditions::matches('not json', ['amount' => 1.0]));
    }

    public function testMinAmountBelowThresholdDoesNotMatch(): void
    {
        self::assertFalse(TriggerConditions::matches('{"min_amount": 50}', ['amount' => 49.99]));
    }

    public function testMinAmountAtThresholdMatches(): void
    {
        self::assertTrue(TriggerConditions::matches('{"min_amount": 50}', ['amount' => 50]));
    }

    public function testTargetStatusOutsideListDoesNotMatch(): void
    {
        self::assertFalse(TriggerConditions::matches('{"target_statuses": [3, 4]}', ['status_id' => 2]));
    }

    public function testTargetStatusInsideListMatches(): void
    {
        self::assertTrue(TriggerConditions::matches('{"target_statuses": [3, 4]}', ['status_id' => 4]));
    }

    public function testBothConditionsMustMatch(): void
    {
        $json = '{"min_amount": 50, "target_statuses": [3]}';

        self::assertTrue(TriggerConditions::matches($json, ['amount' => 100, 'status_id' => 3]));
        self::assertFalse(TriggerConditions::matches($json, ['amount' => 10, 'status_id' => 3]));
        self::assertFalse(TriggerConditions::matches($json, ['amount' => 100, 'status_id' => 9]));
    }

    public function testIntOptionReadsKnownKey(): void
    {
        self::assertSame(48, TriggerConditions::intOption('{"delay_hours": 48}', 'delay_hours', 24));
    }

    public function testIntOptionFallsBackToDefault(): void
    {
        self::assertSame(24, TriggerConditions::intOption('{}', 'delay_hours', 24));
        self::assertSame(24, TriggerConditions::intOption(null, 'delay_hours', 24));
    }
}
