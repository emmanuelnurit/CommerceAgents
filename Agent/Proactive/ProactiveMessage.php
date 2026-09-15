<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Proactive;

/**
 * A proactive message ready to display in the front chat widget, produced by
 * a scenario resolver once real data confirms the signal deserves one.
 */
final readonly class ProactiveMessage
{
    public function __construct(
        public string $message,
    ) {
    }
}
