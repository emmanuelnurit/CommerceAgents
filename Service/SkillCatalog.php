<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Service\Run\AgentTriggerType;
use CommerceAgents\Service\Run\TriggerCatalogMapping;
use CommerceAgents\StagedChange\StagedChangeData;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Bibliothèque de skills" (AC6 of MYO-469, delivered here by MYO-506): a
 * presentation layer over the existing {@see AgentPresets} + {@see
 * AgentDefinitionSeeder} + `agent_staged_change` -- no new table, no new
 * concept of its own (plan guardrail: "pas d'état en base = migration
 * déguisée en badge", lesson of MYO-481).
 *
 * A skill is "activable" (state `active`/`inactive`) when a written tool
 * chain already backs it: either an {@see AgentPresets} entry the wizard can
 * turn into an `agent_definition`, or one of the two protected assistants
 * seeded by {@see AgentDefinitionSeeder}. A skill with no tool written yet
 * stays `soon` and is never activable (maquette `myo-469-le-brief.html`
 * l.497-499: A2, A4, B3, B4, C3, D1).
 */
final readonly class SkillCatalog
{
    /** Activable skills: skill code (preset code, or a seeded assistant's `code` column) => tile metadata. */
    private const ACTIVABLE = [
        AgentPresets::CUSTOMER_REVIEWS_REPLY => ['labelKey' => 'Skill: Review replies', 'icon' => 'bi-star-half', 'color' => 'danger'],
        AgentPresets::STOCK_WATCH_RESTOCK => ['labelKey' => 'Skill: Low stock and restock', 'icon' => 'bi-box-seam', 'color' => 'danger'],
        AgentDefinitionSeeder::MERCHANT_CODE => ['labelKey' => 'Skill: Conversational copilot', 'icon' => 'bi-chat-dots', 'color' => 'primary'],
        AgentPresets::CART_ABANDONED => ['labelKey' => 'Skill: Abandoned cart relaunch', 'icon' => 'bi-cart-x', 'color' => 'success'],
        AgentPresets::STUCK_ORDERS_WATCH => ['labelKey' => 'Skill: Stuck orders', 'icon' => 'bi-hourglass-split', 'color' => 'warning'],
        AgentPresets::PRODUCT_SHEET_AUDIT => ['labelKey' => 'Skill: Product sheet audit', 'icon' => 'bi-card-checklist', 'color' => 'info'],
    ];

    /**
     * No written tool yet (maquette l.497-499: A2, A4, B3, B4, C3, D1). Never
     * activable -- {@see self::activate()} rejects these codes.
     */
    private const SOON = [
        'support_message_triage' => ['labelKey' => 'Skill: Support message triage', 'icon' => 'bi-inbox', 'color' => 'secondary'],
        'unhappy_customer_detection' => ['labelKey' => 'Skill: Unhappy customer detection', 'icon' => 'bi-emoji-frown', 'color' => 'secondary'],
        'dormant_stock' => ['labelKey' => 'Skill: Dormant stock', 'icon' => 'bi-card-heading', 'color' => 'secondary'],
        'price_consistency' => ['labelKey' => 'Skill: Price consistency', 'icon' => 'bi-tags', 'color' => 'secondary'],
        'anomaly_detection' => ['labelKey' => 'Skill: Anomaly detection', 'icon' => 'bi-graph-down', 'color' => 'secondary'],
        'campaign_proposal' => ['labelKey' => 'Skill: Campaign proposal', 'icon' => 'bi-megaphone', 'color' => 'secondary'],
    ];

    public function __construct(
        private AgentDefinitionManager $definitionManager,
        private AgentSpendRepository $spendRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return list<array{
     *     code: string, labelKey: string, icon: string, color: string, state: string,
     *     agentDefinitionId: ?int, acceptanceRate: ?float, costPerProposalEur: ?float, editUrl: ?string
     * }>
     */
    public function all(): array
    {
        $rows = [];
        foreach (self::ACTIVABLE as $code => $meta) {
            $rows[] = $this->buildActivableRow($code, $meta);
        }
        foreach (self::SOON as $code => $meta) {
            $rows[] = [
                'code' => $code,
                'labelKey' => $meta['labelKey'],
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'state' => 'soon',
                'agentDefinitionId' => null,
                'acceptanceRate' => null,
                'costPerProposalEur' => null,
                'editUrl' => null,
            ];
        }

        return $rows;
    }

    /**
     * Activates a skill: reuses the `agent_definition` already backing it if
     * one exists (reactivation, never wiping its capabilities/history), or
     * creates a fresh one from the {@see AgentPresets} entry via {@see
     * AgentDefinitionManager::save()} -- the exact write path the wizard
     * itself uses, so capabilities/triggers/channels are seeded the same way.
     */
    public function activate(string $code): void
    {
        if (!isset(self::ACTIVABLE[$code])) {
            throw new \RuntimeException(\sprintf('Skill "%s" is not activable (no tool written yet, or unknown code)', $code));
        }

        $existing = $this->findExisting($code);
        if ($existing !== null) {
            if (!$existing->getEnabled()) {
                $existing->setEnabled(1)->save();
            }

            return;
        }

        $preset = AgentPresets::find($code);
        if ($preset === null) {
            // The seeded protected assistants (e.g. merchant_assistant) always exist from
            // module install (AgentDefinitionSeeder); reaching here means it is missing,
            // which is an install-time problem this catalog cannot repair on its own.
            throw new \RuntimeException(\sprintf('Skill "%s" has no matching agent_definition and no preset to create one from', $code));
        }

        $this->definitionManager->save(null, [
            'title' => $preset['title'],
            'description' => $preset['subtitle'],
            'rolePrompt' => $preset['rolePrompt'],
            'model' => '',
            'provider' => null,
            'monthlyBudgetUsd' => null,
            'enabled' => true,
            'capabilities' => $preset['capabilities'],
            'triggers' => $this->technicalTriggers($preset['triggers']),
            'channels' => $preset['channel'] !== null ? [['connectorCode' => $preset['channel'], 'enabled' => true]] : [],
            'presetCode' => $code,
        ]);
    }

    /**
     * Disables the skill's `agent_definition` without deleting it or its
     * history (staged changes, runs, memory) -- MYO-506 AC5.
     */
    public function deactivate(string $code): void
    {
        $existing = $this->findExisting($code);
        if ($existing !== null && $existing->getEnabled()) {
            $existing->setEnabled(0)->save();
        }
    }

    /**
     * @param array{labelKey: string, icon: string, color: string} $meta
     *
     * @return array{code: string, labelKey: string, icon: string, color: string, state: string, agentDefinitionId: ?int, acceptanceRate: ?float, costPerProposalEur: ?float, editUrl: ?string}
     */
    private function buildActivableRow(string $code, array $meta): array
    {
        $definitions = $this->definitionsFor($code);
        $representative = $this->representative($definitions);
        $agentDefinitionId = $representative?->getId();
        $ids = array_map(static fn (AgentDefinition $d): int => $d->getId(), $definitions);

        return [
            'code' => $code,
            'labelKey' => $meta['labelKey'],
            'icon' => $meta['icon'],
            'color' => $meta['color'],
            'state' => $representative !== null && $representative->getEnabled() ? 'active' : 'inactive',
            'agentDefinitionId' => $agentDefinitionId,
            'acceptanceRate' => $this->acceptanceRate($ids),
            'costPerProposalEur' => $this->costPerProposalEur($ids),
            'editUrl' => $agentDefinitionId !== null ? $this->urlGenerator->generate('commerceagents_agents_edit', ['id' => $agentDefinitionId]) : null,
        ];
    }

    /**
     * Every `agent_definition` ever created for this skill: by preset code
     * for wizard-created agents, falling back to the `code` column for the
     * seeded protected assistants (their `preset_code` is always null --
     * {@see AgentDefinitionSeeder} never sets it).
     *
     * @return list<AgentDefinition>
     */
    private function definitionsFor(string $code): array
    {
        $byPreset = AgentDefinitionQuery::create()->filterByPresetCode($code)->find()->getData();
        if ($byPreset !== []) {
            return $byPreset;
        }

        return AgentDefinitionQuery::create()->filterByCode($code)->find()->getData();
    }

    private function findExisting(string $code): ?AgentDefinition
    {
        return AgentDefinitionQuery::create()->filterByPresetCode($code)->findOne()
            ?? AgentDefinitionQuery::create()->filterByCode($code)->findOne();
    }

    /**
     * @param list<AgentDefinition> $definitions
     */
    private function representative(array $definitions): ?AgentDefinition
    {
        foreach ($definitions as $definition) {
            if ($definition->getEnabled()) {
                return $definition;
            }
        }

        return $definitions[0] ?? null;
    }

    /**
     * Real acceptance rate (MYO-506 AC3): applied vs rejected `agent_staged_change`
     * rows across every `agent_definition` this skill has ever had. `null`
     * when neither ever happened -- never a made-up number.
     *
     * @param list<int> $agentDefinitionIds
     */
    private function acceptanceRate(array $agentDefinitionIds): ?float
    {
        if ($agentDefinitionIds === []) {
            return null;
        }

        $rows = AgentStagedChangeQuery::create()
            ->filterByAgentDefinitionId($agentDefinitionIds)
            ->filterByStatus([StagedChangeData::STATUS_APPLIED, StagedChangeData::STATUS_REJECTED], Criteria::IN)
            ->groupByStatus()
            ->select(['Status'])
            ->withColumn('COUNT(id)', 'Cnt')
            ->find();

        $applied = 0;
        $rejected = 0;
        foreach ($rows as $row) {
            if ($row['Status'] === StagedChangeData::STATUS_APPLIED) {
                $applied = (int) $row['Cnt'];
            } elseif ($row['Status'] === StagedChangeData::STATUS_REJECTED) {
                $rejected = (int) $row['Cnt'];
            }
        }

        $total = $applied + $rejected;

        return $total > 0 ? round($applied / $total, 4) : null;
    }

    /**
     * Real cost per proposal in EUR (MYO-506 AC4): lifetime LLM spend of
     * every `agent_definition` this skill has ever had (existing {@see
     * AgentSpendRepository} pipeline, USD), divided by how many proposals it
     * produced, converted with the fixed-in-code rate ({@see
     * ModelCatalog::usdToEurRate()}, MYO-489/495) -- never re-derived here.
     * `null` when it never proposed anything.
     *
     * @param list<int> $agentDefinitionIds
     */
    private function costPerProposalEur(array $agentDefinitionIds): ?float
    {
        if ($agentDefinitionIds === []) {
            return null;
        }

        $proposalCount = AgentStagedChangeQuery::create()->filterByAgentDefinitionId($agentDefinitionIds)->count();
        if ($proposalCount === 0) {
            return null;
        }

        $epoch = new \DateTimeImmutable('@0');
        $totalCostUsd = 0.0;
        foreach ($agentDefinitionIds as $id) {
            $totalCostUsd += $this->spendRepository->costSince($id, $epoch);
        }

        return round($totalCostUsd / $proposalCount * ModelCatalog::usdToEurRate(), 4);
    }

    /**
     * Converts an {@see AgentPresets} `triggers` entry (business-facing
     * codes: {@see TriggerCatalog}) into the technical shape {@see
     * AgentDefinitionManager::save()} expects, the same conversion
     * `AgentsController::parseTriggers()` does for the wizard's submitted
     * checkboxes -- kept small and duplicated rather than extracted, since
     * the only shared source of truth that matters here ({@see
     * \CommerceAgents\Service\Run\TriggerCatalogMapping}) already is one.
     *
     * @param list<array{type: string, hours?: int, time?: string, threshold?: int}> $presetTriggers
     *
     * @return list<array{type: string, cronExpression: ?string, eventName: ?string, conditions: ?array<string, mixed>}>
     */
    private function technicalTriggers(array $presetTriggers): array
    {
        $technical = [];
        foreach ($presetTriggers as $trigger) {
            $technical[] = match ($trigger['type']) {
                TriggerCatalog::CART_ABANDONED => TriggerCatalogMapping::technicalFor(TriggerCatalog::CART_ABANDONED)
                    + ['conditions' => ['delay_hours' => $trigger['hours'] ?? 24]],
                TriggerCatalog::LOW_STOCK => TriggerCatalogMapping::technicalFor(TriggerCatalog::LOW_STOCK)
                    + ['conditions' => ['threshold' => $trigger['threshold'] ?? 5]],
                TriggerCatalog::NEW_ORDER, TriggerCatalog::NEW_CUSTOMER => TriggerCatalogMapping::technicalFor($trigger['type']) + ['conditions' => null],
                TriggerCatalog::ORDER_STATUS_CHANGE => TriggerCatalogMapping::technicalFor(TriggerCatalog::ORDER_STATUS_CHANGE)
                    + ['conditions' => ['target_statuses' => []]],
                TriggerCatalog::SCHEDULE => ['type' => AgentTriggerType::CRON, 'eventName' => null, 'cronExpression' => self::timeToCron($trigger['time'] ?? '09:00'), 'conditions' => null],
                default => null,
            };
        }

        return array_values(array_filter($technical));
    }

    private static function timeToCron(string $time): string
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return \sprintf('%d %d * * *', (int) $minute, (int) $hour);
    }
}
