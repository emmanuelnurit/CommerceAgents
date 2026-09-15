<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

final readonly class ToolContext
{
    /**
     * @param list<string>|null $capabilities capabilities granted to the agent
     *                                        definition running this context;
     *                                        null for the historical chat
     *                                        contexts, where tools rely on
     *                                        their own isAllowed() rules only
     * @param string            $locale       every production call site passes this
     *                                        explicitly, resolved through
     *                                        AssistantLocaleResolver (MYO-274); the
     *                                        default below only saves tests that don't
     *                                        care about locale from naming one
     */
    public function __construct(
        public bool $isAdmin = false,
        public ?int $customerId = null,
        public ?int $adminId = null,
        public ?int $conversationId = null,
        public ?string $sessionId = null,
        public string $locale = 'fr_FR',
        public string $currencyCode = 'EUR',
        public ?int $agentDefinitionId = null,
        public ?array $capabilities = null,
    ) {
    }

    public function hasCapability(string $capability): bool
    {
        return $this->capabilities !== null && \in_array($capability, $this->capabilities, true);
    }
}
