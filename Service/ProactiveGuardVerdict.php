<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

final readonly class ProactiveGuardVerdict
{
    private function __construct(
        public bool $eligible,
        public ?string $reason,
    ) {
    }

    public static function eligible(): self
    {
        return new self(true, null);
    }

    public static function blocked(string $reason): self
    {
        return new self(false, $reason);
    }
}
