<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

final readonly class BudgetGuard
{
    public function __construct(
        private AgentConfigService $configService,
        private TokenUsageRepository $tokenUsageRepository,
    ) {
    }

    public function status(\DateTimeImmutable $now = new \DateTimeImmutable()): BudgetStatus
    {
        return new BudgetStatus(
            budget: $this->configService->getMonthlyBudgetUsd(),
            spent: $this->tokenUsageRepository->costSince($now->modify('first day of this month')->setTime(0, 0)),
            warningPercent: $this->configService->getBudgetWarningPercent(),
            blockWhenExceeded: $this->configService->isBudgetBlocking(),
        );
    }
}
