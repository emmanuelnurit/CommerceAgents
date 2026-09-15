<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

/**
 * Dedicated audit trail of agent actions (MYO-286 item 4), the same mechanism
 * as Thelia's AdminLog but scoped to tool calls made by CommerceAgents agents
 * over any channel (chat, MCP, autonomous run). Prerequisite to ever widening
 * agent write capabilities: what an agent can write must be traceable before
 * it is extended.
 */
interface AgentActionLoggerInterface
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_DENIED = 'denied';
    public const STATUS_ERROR = 'error';

    /**
     * @param array<string, mixed> $arguments the raw tool call arguments, for traceability
     */
    public function log(
        string $toolName,
        ?string $capability,
        string $status,
        ToolContext $ctx,
        array $arguments = [],
        ?string $error = null,
    ): void;
}
