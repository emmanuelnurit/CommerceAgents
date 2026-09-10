<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Model\AgentMessageQuery;
use CommerceAgents\Model\Map\AgentConversationTableMap;
use CommerceAgents\Model\Map\AgentMessageTableMap;

/**
 * Aggregates the token usage persisted on assistant messages, for the
 * back-office consumption counter.
 */
final readonly class TokenUsageRepository
{
    public const AGENT_TYPES = ['shopping', 'merchant'];

    /**
     * @return array<string, array<string, array{calls: int, input: int, output: int, total: int}>>
     *                                                                                                 period => agent type ('all' for the sum) => counters
     */
    public function summarize(\DateTimeImmutable $now = new \DateTimeImmutable()): array
    {
        $periods = [
            'today' => $now->setTime(0, 0),
            'last_30_days' => $now->setTime(0, 0)->modify('-29 days'),
            'all_time' => null,
        ];

        $summary = [];
        foreach ($periods as $period => $since) {
            $summary[$period] = [];
            $all = ['calls' => 0, 'input' => 0, 'output' => 0, 'total' => 0];

            foreach (self::AGENT_TYPES as $type) {
                $counters = $this->aggregate($type, $since);
                $summary[$period][$type] = $counters;
                foreach ($all as $key => $value) {
                    $all[$key] = $value + $counters[$key];
                }
            }

            $summary[$period]['all'] = $all;
        }

        return $summary;
    }

    /**
     * @return array{calls: int, input: int, output: int, total: int}
     */
    private function aggregate(string $agentType, ?\DateTimeImmutable $since): array
    {
        $query = AgentMessageQuery::create()
            ->filterByRole('assistant')
            ->useAgentConversationQuery()
                ->filterByType($agentType)
            ->endUse()
            ->where(sprintf('(%s > 0 OR %s > 0)', AgentMessageTableMap::COL_TOKENS_IN, AgentMessageTableMap::COL_TOKENS_OUT))
            ->withColumn('COUNT('.AgentMessageTableMap::COL_ID.')', 'calls')
            ->withColumn('COALESCE(SUM('.AgentMessageTableMap::COL_TOKENS_IN.'), 0)', 'input')
            ->withColumn('COALESCE(SUM('.AgentMessageTableMap::COL_TOKENS_OUT.'), 0)', 'output')
            ->select(['calls', 'input', 'output']);

        if ($since !== null) {
            $query->filterByCreatedAt(['min' => $since]);
        }

        $row = $query->findOne() ?? [];

        $input = (int) ($row['input'] ?? 0);
        $output = (int) ($row['output'] ?? 0);

        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'input' => $input,
            'output' => $output,
            'total' => $input + $output,
        ];
    }
}
