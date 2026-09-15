<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

final readonly class ProactiveSessionState
{
    /**
     * @param list<string> $triggeredScenarios
     */
    public function __construct(
        public bool $dismissed,
        public int $promptCount,
        public ?\DateTimeImmutable $lastPromptedAt,
        public array $triggeredScenarios,
    ) {
    }
}
