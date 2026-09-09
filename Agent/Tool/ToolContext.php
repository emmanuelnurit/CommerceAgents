<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

final readonly class ToolContext
{
    public function __construct(
        public bool $isAdmin = false,
        public ?int $customerId = null,
        public ?string $sessionId = null,
        public string $locale = 'fr_FR',
        public string $currencyCode = 'EUR',
    ) {
    }
}
