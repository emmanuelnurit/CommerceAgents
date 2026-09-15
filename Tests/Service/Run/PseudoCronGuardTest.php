<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Run;

use CommerceAgents\CommerceAgents;
use CommerceAgents\Service\Run\PseudoCronGuard;
use Thelia\Test\IntegrationTestCase;

/**
 * Plan MYO-226 §3.3 point 4: the BO pseudo-cron fallback only takes over once
 * the real `commerce-agents:run-due` cron looks stale, and then respects its
 * own cooldown.
 */
class PseudoCronGuardTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The two ticks are CommerceAgents module config, not fixture rows the
        // transaction rollback alone resets: phpunit's APP_ENV=test still runs
        // against the shared "db" database here (DDEV injects real DATABASE_*
        // env vars that Dotenv never overrides), and real BO page loads tick
        // these same keys via PseudoCronFallbackSubscriber (MYO-303). Force a
        // clean baseline so "no tick ever recorded" is actually true.
        CommerceAgents::setConfigValue('run_due_last_system_tick_at', '');
        CommerceAgents::setConfigValue('run_due_last_pseudo_tick_at', '');
    }

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
