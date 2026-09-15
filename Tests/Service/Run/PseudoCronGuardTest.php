<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\Service\Run\PseudoCronGuard;
use Thelia\Test\IntegrationTestCase;

/**
 * Plan MYO-226 §3.3 point 4: the BO pseudo-cron fallback only takes over once
 * the real `commerce-agents:run-due` cron looks stale, and then respects its
 * own cooldown.
 */
class PseudoCronGuardTest extends IntegrationTestCase
{
    public function testRunsWhenNeitherTickWasEverRecorded(): void
    {
        $guard = new PseudoCronGuard();

        self::assertTrue($guard->shouldRunNow(new \DateTimeImmutable('2026-09-15 10:00:00')));
    }

    public function testStaysOutOfTheWayWhileTheSystemCronIsFresh(): void
    {
        $guard = new PseudoCronGuard();
        $guard->markSystemTick(new \DateTimeImmutable('2026-09-15 09:55:00'));

        self::assertFalse($guard->shouldRunNow(new \DateTimeImmutable('2026-09-15 10:00:00')));
    }

    public function testTakesOverOnceTheSystemCronIsStale(): void
    {
        $guard = new PseudoCronGuard();
        $guard->markSystemTick(new \DateTimeImmutable('2026-09-15 09:00:00'));

        self::assertTrue($guard->shouldRunNow(new \DateTimeImmutable('2026-09-15 10:00:00')));
    }

    public function testRespectsItsOwnCooldownOnceItHasFired(): void
    {
        $guard = new PseudoCronGuard();
        $guard->markSystemTick(new \DateTimeImmutable('2026-09-15 09:00:00'));
        $guard->markPseudoTick(new \DateTimeImmutable('2026-09-15 10:00:00'));

        self::assertFalse($guard->shouldRunNow(new \DateTimeImmutable('2026-09-15 10:03:00')));
        self::assertTrue($guard->shouldRunNow(new \DateTimeImmutable('2026-09-15 10:06:00')));
    }
}
