<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * Server-side gate a proactive signal must clear before any data resolution
 * or LLM call, in the strict order of plan MYO-236: a closed/refused widget
 * always wins, then frequency (count + delay), then scenario repetition,
 * then cost. Never call an LLM before this returns eligible(). Pure by
 * design: the caller resolves the max-prompts setting (AgentConfigService)
 * and the budget verdict (BudgetGuard) so this stays trivially testable.
 */
final readonly class ProactiveGuard
{
    public const MIN_DELAY_SECONDS = 90;

    public function evaluate(
        ProactiveSessionState $state,
        string $scenario,
        \DateTimeImmutable $now,
        int $maxPrompts,
        bool $budgetBlocked,
    ): ProactiveGuardVerdict {
        if ($state->dismissed) {
            return ProactiveGuardVerdict::blocked('dismissed');
        }

        if ($state->promptCount >= $maxPrompts) {
            return ProactiveGuardVerdict::blocked('rate_limited');
        }

        if ($state->lastPromptedAt !== null && ($now->getTimestamp() - $state->lastPromptedAt->getTimestamp()) < self::MIN_DELAY_SECONDS) {
            return ProactiveGuardVerdict::blocked('rate_limited');
        }

        if (\in_array($scenario, $state->triggeredScenarios, true)) {
            return ProactiveGuardVerdict::blocked('scenario_repeated');
        }

        if ($budgetBlocked) {
            return ProactiveGuardVerdict::blocked('budget_blocked');
        }

        return ProactiveGuardVerdict::eligible();
    }
}
