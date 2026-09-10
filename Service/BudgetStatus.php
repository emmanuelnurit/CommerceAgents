<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

/**
 * Month-to-date LLM spend compared with the budget set in the back-office.
 * Amounts are USD, the currency providers bill in.
 */
final readonly class BudgetStatus
{
    public const UNLIMITED = 'unlimited';
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const EXCEEDED = 'exceeded';

    public function __construct(
        public float $budget,
        public float $spent,
        public int $warningPercent,
        public bool $blockWhenExceeded,
    ) {
    }

    public function isLimited(): bool
    {
        return $this->budget > 0;
    }

    public function percentUsed(): ?float
    {
        if (!$this->isLimited()) {
            return null;
        }

        return round($this->spent / $this->budget * 100, 1);
    }

    public function remaining(): ?float
    {
        if (!$this->isLimited()) {
            return null;
        }

        return max(0.0, round($this->budget - $this->spent, 8));
    }

    public function state(): string
    {
        if (!$this->isLimited()) {
            return self::UNLIMITED;
        }
        if ($this->spent >= $this->budget) {
            return self::EXCEEDED;
        }
        if ($this->warningPercent > 0 && $this->percentUsed() >= $this->warningPercent) {
            return self::WARNING;
        }

        return self::OK;
    }

    public function isBlocked(): bool
    {
        return $this->blockWhenExceeded && $this->state() === self::EXCEEDED;
    }
}
