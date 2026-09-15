<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Proactive;

/**
 * A client-side event (cart idle, low stock viewed, etc.) that may warrant a
 * proactive solicitation. The signal type doubles as the scenario key used
 * by the server-side gate (ProactiveGuard) to dedupe within a session.
 */
final readonly class ProactiveSignal
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $type,
        public array $context = [],
    ) {
    }
}
