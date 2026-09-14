<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Model\AgentMessageQuery;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\Map\AgentMessageTableMap;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * LLM spend of one configurable agent, aggregated through its runs: each run
 * owns an agent_conversation whose assistant messages carry the cost computed
 * by the existing pipeline (plan MYO-226 §3.5).
 */
final readonly class AgentSpendRepository
{
    public function monthlyCost(int $agentDefinitionId, \DateTimeImmutable $now = new \DateTimeImmutable()): float
    {
        return $this->costSince($agentDefinitionId, $now->modify('first day of this month')->setTime(0, 0));
    }

    public function costSince(int $agentDefinitionId, \DateTimeImmutable $since): float
    {
        $conversationIds = AgentRunQuery::create()
            ->filterByAgentDefinitionId($agentDefinitionId)
            ->filterByConversationId(null, Criteria::ISNOTNULL)
            ->select('ConversationId')
            ->find()
            ->getData();

        if ($conversationIds === []) {
            return 0.0;
        }

        $row = AgentMessageQuery::create()
            ->filterByConversationId($conversationIds, Criteria::IN)
            ->filterByRole('assistant')
            ->filterByCreatedAt(['min' => $since])
            ->withColumn('COALESCE(SUM('.AgentMessageTableMap::COL_COST.'), 0)', 'cost')
            ->select(['cost', 'cost'])
            ->findOne();

        // A single-column select() comes back as a scalar, a multi-column one as an array.
        return (float) (\is_array($row) ? ($row['cost'] ?? 0) : ($row ?? 0));
    }
}
