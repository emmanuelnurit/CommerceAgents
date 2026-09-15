<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

final readonly class ToolContext
{
    public const CHANNEL_CHAT = 'chat';
    public const CHANNEL_MCP = 'mcp';
    public const CHANNEL_RUN = 'run';

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
     * @param string            $channel      which surface this call came through
     *                                        (MYO-286 audit log): CHANNEL_CHAT for the
     *                                        front/merchant chat widgets, CHANNEL_MCP
     *                                        for an external MCP client, CHANNEL_RUN
     *                                        for an autonomous trigger-fired run
     * @param ?int              $agentRunId   the agent_run this call executes under
     *                                        (MYO-328); only AgentRunner (CHANNEL_RUN)
     *                                        has a real run to report, every other
     *                                        call site leaves it null
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
        public string $channel = self::CHANNEL_CHAT,
        public ?int $agentRunId = null,
    ) {
    }

    public function hasCapability(string $capability): bool
    {
        return $this->capabilities !== null && \in_array($capability, $this->capabilities, true);
    }
}
