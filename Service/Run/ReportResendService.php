<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Run;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Tool\Channel\ChannelBroadcaster;

/**
 * "Resend this report" (MYO-335, BO Results tab): re-delivers an existing
 * agent_run's summary through the agent's configured channels via the same
 * ChannelBroadcaster path SendToChannelTool uses, instead of a second
 * drifting implementation of the send/log logic.
 *
 * Logged under the same agent_run_id as the original run (not a fresh
 * agent_run row): a resend is a re-delivery of an already-produced report,
 * not a new execution, so agent_outbound_message.agent_run_id keeps pointing
 * at the report it actually came from.
 */
final readonly class ReportResendService
{
    public function __construct(
        private ChannelBroadcaster $broadcaster,
    ) {
    }

    /**
     * @return array{results: list<array{channelId: int, status?: string, error?: string, changeId?: int}>, error?: string}
     */
    public function resend(AgentRun $run): array
    {
        $summary = trim((string) $run->getSummary());
        if ($summary === '') {
            return ['results' => [], 'error' => 'This run has no report to resend'];
        }

        $ctx = new ToolContext(
            isAdmin: true,
            agentDefinitionId: $run->getAgentDefinitionId(),
            channel: ToolContext::CHANNEL_RUN,
            agentRunId: $run->getId(),
        );

        return $this->broadcaster->broadcast($run->getAgentDefinitionId(), new ChannelMessage(null, $summary), $ctx);
    }
}
