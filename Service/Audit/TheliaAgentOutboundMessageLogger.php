<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Audit;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentOutboundMessage;
use Thelia\Log\Tlog;

final readonly class TheliaAgentOutboundMessageLogger implements AgentOutboundMessageLoggerInterface
{
    public function log(
        ToolContext $ctx,
        string $channel,
        ?string $recipient,
        string $status,
        ?string $error = null,
    ): void {
        if ($ctx->agentRunId === null || $ctx->agentDefinitionId === null) {
            // No real agent_run to attach to (e.g. an interactive chat call
            // to send_to_channel): agent_run_id is NOT NULL, so there is
            // nothing truthful to persist. Not a failure worth an error log.
            return;
        }

        try {
            (new AgentOutboundMessage())
                ->setAgentRunId($ctx->agentRunId)
                ->setAgentDefinitionId($ctx->agentDefinitionId)
                ->setChannel($channel)
                ->setRecipient($recipient)
                ->setStatus($status)
                ->setError($error)
                ->setSentAt(new \DateTime())
                ->save();
        } catch (\Exception $exception) {
            // An audit-log failure must never break the tool call it is logging (mirrors TheliaAgentActionLogger).
            Tlog::getInstance()->err('Failed to insert new entry in AgentOutboundMessage: {ex}', ['ex' => $exception]);
        }
    }
}
