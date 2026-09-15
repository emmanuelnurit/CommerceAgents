<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use CommerceAgents\CommerceAgents;

/**
 * Decides whether the BO-traffic pseudo-cron fallback should run (plan
 * MYO-226 §3.3 point 4: "à défaut, fallback pseudo-cron sur trafic BO").
 *
 * Two independent guards, both cooldowns tracked as module config
 * timestamps rather than a table — this is infrastructure bookkeeping, not
 * domain data:
 *  - the real `commerce-agents:run-due` command marks itself alive on every
 *    run; while it has ticked recently, the system cron is doing the job and
 *    the fallback stays out of the way;
 *  - once it goes stale, the fallback takes over, but never more often than
 *    its own cooldown, so a burst of admin page loads does not turn into a
 *    burst of run-due passes.
 */
final class PseudoCronGuard
{
    private const SYSTEM_CRON_KEY = 'run_due_last_system_tick_at';
    private const PSEUDO_CRON_KEY = 'run_due_last_pseudo_tick_at';

    /** System cron considered unavailable once its last tick is older than this. */
    private const SYSTEM_CRON_STALE_AFTER = 'PT10M';

    /** Minimum delay between two pseudo-cron passes. */
    private const PSEUDO_CRON_COOLDOWN = 'PT5M';

    public function shouldRunNow(\DateTimeImmutable $now): bool
    {
        $lastSystemTick = $this->readTimestamp(self::SYSTEM_CRON_KEY);
        if ($lastSystemTick !== null && $lastSystemTick > $now->sub(new \DateInterval(self::SYSTEM_CRON_STALE_AFTER))) {
            return false;
        }

        $lastPseudoTick = $this->readTimestamp(self::PSEUDO_CRON_KEY);

        return $lastPseudoTick === null || $lastPseudoTick <= $now->sub(new \DateInterval(self::PSEUDO_CRON_COOLDOWN));
    }

    public function markSystemTick(\DateTimeImmutable $now): void
    {
        CommerceAgents::setConfigValue(self::SYSTEM_CRON_KEY, $now->format(\DATE_ATOM));
    }

    public function markPseudoTick(\DateTimeImmutable $now): void
    {
        CommerceAgents::setConfigValue(self::PSEUDO_CRON_KEY, $now->format(\DATE_ATOM));
    }

    private function readTimestamp(string $key): ?\DateTimeImmutable
    {
        $raw = CommerceAgents::getConfigValue($key);
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
