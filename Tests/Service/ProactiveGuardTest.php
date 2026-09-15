<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Service\ProactiveGuard;
use CommerceAgents\Service\ProactiveSessionState;
use PHPUnit\Framework\TestCase;

/**
 * Gate order per plan MYO-236 / MYO-246: dismissed > frequency (count then
 * delay) > scenario repetition > budget. Each test isolates one gate by
 * keeping every other input clean, so a regression that swaps the order
 * fails on the right test.
 */
class ProactiveGuardTest extends TestCase
{
    private ProactiveGuard $guard;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->guard = new ProactiveGuard();
        $this->now = new \DateTimeImmutable('2026-09-15 12:00:00');
    }

    public function testEligibleWhenEveryGateClears(): void
    {
        $state = new ProactiveSessionState(dismissed: false, promptCount: 0, lastPromptedAt: null, triggeredScenarios: []);

        $verdict = $this->guard->evaluate($state, 'cart_idle', $this->now, maxPrompts: 3, budgetBlocked: false);

        $this->assertTrue($verdict->eligible);
        $this->assertNull($verdict->reason);
    }

    public function testDismissedSessionBlocksRegardlessOfEverythingElse(): void
    {
        $state = new ProactiveSessionState(dismissed: true, promptCount: 0, lastPromptedAt: null, triggeredScenarios: []);

        $verdict = $this->guard->evaluate($state, 'cart_idle', $this->now, maxPrompts: 3, budgetBlocked: false);

        $this->assertFalse($verdict->eligible);
        $this->assertSame('dismissed', $verdict->reason);
    }

    public function testRateLimitedWhenPromptCountReachedTheConfiguredMax(): void
    {
        $state = new ProactiveSessionState(dismissed: false, promptCount: 3, lastPromptedAt: null, triggeredScenarios: []);

        $verdict = $this->guard->evaluate($state, 'cart_idle', $this->now, maxPrompts: 3, budgetBlocked: false);

        $this->assertFalse($verdict->eligible);
        $this->assertSame('rate_limited', $verdict->reason);
    }

    public function testRateLimitedWhenTheMinimumDelayHasNotElapsed(): void
    {
        $state = new ProactiveSessionState(
            dismissed: false,
            promptCount: 1,
            lastPromptedAt: $this->now->modify('-89 seconds'),
            triggeredScenarios: [],
        );

        $verdict = $this->guard->evaluate($state, 'cart_idle', $this->now, maxPrompts: 3, budgetBlocked: false);

        $this->assertFalse($verdict->eligible);
        $this->assertSame('rate_limited', $verdict->reason);
    }

    public function testEligibleOnceTheMinimumDelayHasElapsed(): void
    {
        $state = new ProactiveSessionState(
            dismissed: false,
            promptCount: 1,
            lastPromptedAt: $this->now->modify('-90 seconds'),
            triggeredScenarios: [],
        );

        $verdict = $this->guard->evaluate($state, 'cart_idle', $this->now, maxPrompts: 3, budgetBlocked: false);

        $this->assertTrue($verdict->eligible);
    }

    public function testScenarioAlreadyTriggeredThisSessionIsBlocked(): void
    {
        $state = new ProactiveSessionState(dismissed: false, promptCount: 1, lastPromptedAt: null, triggeredScenarios: ['cart_idle']);

        $verdict = $this->guard->evaluate($state, 'cart_idle', $this->now, maxPrompts: 3, budgetBlocked: false);

        $this->assertFalse($verdict->eligible);
        $this->assertSame('scenario_repeated', $verdict->reason);
    }

    public function testADifferentScenarioIsNotBlockedByAPreviousOne(): void
    {
        $state = new ProactiveSessionState(dismissed: false, promptCount: 1, lastPromptedAt: null, triggeredScenarios: ['cart_idle']);

        $verdict = $this->guard->evaluate($state, 'low_stock_viewed', $this->now, maxPrompts: 3, budgetBlocked: false);

        $this->assertTrue($verdict->eligible);
    }

    public function testBudgetBlockedGateIsCheckedLast(): void
    {
        $state = new ProactiveSessionState(dismissed: false, promptCount: 0, lastPromptedAt: null, triggeredScenarios: []);

        $verdict = $this->guard->evaluate($state, 'cart_idle', $this->now, maxPrompts: 3, budgetBlocked: true);

        $this->assertFalse($verdict->eligible);
        $this->assertSame('budget_blocked', $verdict->reason);
    }
}
