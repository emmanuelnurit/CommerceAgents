<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Audit;

use CommerceAgents\Agent\Tool\AgentActionLoggerInterface;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentActionLog;
use Thelia\Log\Tlog;

final readonly class TheliaAgentActionLogger implements AgentActionLoggerInterface
{
    public function log(
        string $toolName,
        ?string $capability,
        string $status,
        ToolContext $ctx,
        array $arguments = [],
        ?string $error = null,
    ): void {
        try {
            (new AgentActionLog())
                ->setToolName($toolName)
                ->setCapability($capability)
                ->setChannel($ctx->channel)
                ->setStatus($status)
                ->setConversationId($ctx->conversationId)
                ->setAgentDefinitionId($ctx->agentDefinitionId)
                ->setAdminId($ctx->adminId)
                ->setCustomerId($ctx->customerId)
                ->setArguments(json_encode($arguments, \JSON_UNESCAPED_UNICODE) ?: '{}')
                ->setError($error)
                ->save();
        } catch (\Exception $exception) {
            // An audit-log failure must never break the tool call it is logging (mirrors Thelia's AdminLog::append).
            Tlog::getInstance()->err('Failed to insert new entry in AgentActionLog: {ex}', ['ex' => $exception]);
        }
    }
}
