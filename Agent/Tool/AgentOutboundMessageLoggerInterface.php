<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

/**
 * Traceability of every outbound message a channel-sending tool produces
 * (MYO-327/MYO-328): one call per element of SendToChannelTool::execute()'s
 * $results, so a single tool call that fans out to N channels yields N rows,
 * unlike agent_run (one row per execution) or agent_action_log (one row per
 * tool call, arguments only, no delivery outcome).
 */
interface AgentOutboundMessageLoggerInterface
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STAGED = 'staged';

    /**
     * Silently skips persistence when $ctx does not carry a real agent_run_id
     * (agent_outbound_message.agent_run_id is NOT NULL — there is nothing
     * truthful to record outside an autonomous run) but must never throw.
     */
    public function log(
        ToolContext $ctx,
        string $channel,
        ?string $recipient,
        string $status,
        ?string $error = null,
        ?string $bodyExcerpt = null,
    ): void;
}
