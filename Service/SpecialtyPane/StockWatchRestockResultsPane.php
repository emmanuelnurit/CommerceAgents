<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentStagedChange;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\AgentPresets;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * Real "Results" tab for the stock-watch-restock specialty (MYO-336 lot 2,
 * MYO-338): a read-only "Propositions à valider" feed built from
 * agent_staged_change rows (target_type = pse_stock) for the current agent,
 * newest first. This pane never approves/rejects a change itself -- it links
 * to the existing commerceagents_changes console for that (StagedChangesController).
 *
 * `hasEverRun` (MYO-338 scope amendment, CTO arbitration on MYO-336 triage):
 * an agent that ran and found nothing to propose must not look identical to
 * one that never ran -- same `hasEverRun` pattern as DailySalesSummaryResultsPane.
 * The template links the "ran but nothing to propose" case to the run history
 * (commerceagents_agents_runs) instead of re-rendering the run analysis here.
 */
final readonly class StockWatchRestockResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    private const TARGET_TYPE = 'pse_stock';
    private const MAX_PROPOSALS = 20;

    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::STOCK_WATCH_RESTOCK
            || \in_array(Capability::INVENTORY_WRITE, $capabilities, true);
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_stock_watch_restock.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        $totalCount = AgentStagedChangeQuery::create()
            ->filterByAgentDefinitionId($definition->getId())
            ->filterByTargetType(self::TARGET_TYPE)
            ->count();

        $changes = AgentStagedChangeQuery::create()
            ->filterByAgentDefinitionId($definition->getId())
            ->filterByTargetType(self::TARGET_TYPE)
            ->orderByCreatedAt(Criteria::DESC)
            ->limit(self::MAX_PROPOSALS)
            ->find();

        return [
            'agentId' => $definition->getId(),
            'proposals' => array_map($this->toProposal(...), $changes->getData()),
            'totalCount' => $totalCount,
            'maxProposals' => self::MAX_PROPOSALS,
            'hasEverRun' => AgentRunQuery::create()->filterByAgentDefinitionId($definition->getId())->exists(),
        ];
    }

    /**
     * @return array{id: int, pseId: int, createdAt: ?\DateTimeInterface, status: string, pseRef: ?string, quantityBefore: mixed, quantityAfter: mixed}
     */
    private function toProposal(AgentStagedChange $change): array
    {
        $before = json_decode((string) $change->getPayloadBefore(), true) ?? [];
        $after = json_decode((string) $change->getPayloadAfter(), true) ?? [];

        return [
            'id' => $change->getId(),
            'pseId' => $change->getTargetId(),
            'createdAt' => $change->getCreatedAt(),
            'status' => $change->getStatus(),
            'pseRef' => $before['pseRef'] ?? null,
            'quantityBefore' => $before['quantity'] ?? null,
            'quantityAfter' => $after['quantity'] ?? null,
        ];
    }
}
