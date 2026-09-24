<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Model\AgentChannel;
use CommerceAgents\Model\AgentChannelQuery;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentStagedChangeQuery;
use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Model\AgentTriggerQuery;
use CommerceAgents\Service\Run\AgentTriggerType;
use CommerceAgents\Service\Run\TriggerCatalogMapping;
use CommerceAgents\Service\Run\TriggerConditions;
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
        private CapabilityCatalog $capabilityCatalog,
    ) {
    }

    /**
     * @return list<array{
     *     code: string, labelKey: string, icon: string, color: string, state: string,
     *     agentDefinitionId: ?int, acceptanceRate: ?float, costPerProposalEur: ?float, editUrl: ?string,
     *     guidedSettings: ?array<string, mixed>
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
                'guidedSettings' => null,
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
     * Applies the guided-settings card's save (MYO-508 AC2/AC4): the chosen
     * tone/detail variant (full-text replace, never a fragment) plus the
     * category/customer scope sentences {@see ScopeCatalog} appends, the
     * matching trigger's threshold/delay/schedule/min_amount/daily-cap, and
     * the monthly budget -- through {@see AgentDefinitionManager::save()},
     * the same write path the wizard itself uses, with every other field
     * (capabilities, channels, other triggers, title/description/model)
     * reconstructed from the current row so this call never touches them.
     *
     * Every guided save recomposes `role_prompt` from the *canonical* preset
     * text (the matched variant, or the preset's single prompt when it has
     * no tone/detail variants) rather than the current possibly-scoped
     * value: appending scope sentences onto an already-scoped prompt would
     * otherwise pile up duplicate sentences across repeated saves.
     *
     * @param array{
     *     variant: ?string, threshold: ?int, delayHours: ?int, minAmount: ?float,
     *     time: ?string, dailyCap: ?int, monthlyBudgetUsd: ?float,
     *     categoryTitles: list<string>, customerScope: ?string
     * } $input
     */
    public function saveGuidedSettings(string $code, array $input): void
    {
        if ($code === AgentDefinitionSeeder::MERCHANT_CODE) {
            throw new \RuntimeException('The conversational copilot has no guided settings; use expert mode.');
        }

        $definition = $this->findExisting($code);
        if ($definition === null) {
            throw new \RuntimeException(\sprintf('Skill "%s" has not been activated yet.', $code));
        }

        $preset = AgentPresets::find($code);
        $variants = $preset['rolePromptVariants'] ?? [];
        $variantKind = self::variantKind($variants);

        if ($variantKind !== null && ($input['variant'] === null || !isset($variants[$input['variant']]))) {
            throw new \RuntimeException('Pick a tone before saving, or edit the text in expert mode.');
        }

        $rolePrompt = $variantKind !== null ? $variants[$input['variant']] : (string) ($preset['rolePrompt'] ?? $definition->getRolePrompt());
        $rolePrompt = ScopeCatalog::appendCategoryScope($rolePrompt, $input['categoryTitles']);
        $rolePrompt = ScopeCatalog::appendCustomerScope($rolePrompt, $input['customerScope'] ?? ScopeCatalog::CUSTOMER_SCOPE_ALL);

        $this->definitionManager->save($definition->getId(), [
            'title' => $definition->getTitle(),
            'description' => (string) $definition->getDescription(),
            'rolePrompt' => $rolePrompt,
            'model' => (string) $definition->getModel(),
            'provider' => $definition->getProvider(),
            'monthlyBudgetUsd' => $input['monthlyBudgetUsd'],
            'enabled' => (bool) $definition->getEnabled(),
            'capabilities' => $this->definitionManager->capabilitiesFor($definition->getId()),
            'triggers' => $this->triggersDataWithGuidedEdits($definition, $input),
            'channels' => self::channelsData($definition->getId()),
        ]);
    }

    /**
     * @param array{threshold: ?int, delayHours: ?int, minAmount: ?float, time: ?string, dailyCap: ?int} $input
     *
     * @return list<array{type: string, cronExpression: ?string, eventName: ?string, conditions: ?array<string, mixed>}>
     */
    private function triggersDataWithGuidedEdits(AgentDefinition $definition, array $input): array
    {
        $rows = AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->find()->getData();

        $data = [];
        foreach ($rows as $trigger) {
            $catalogCode = TriggerCatalogMapping::catalogCodeFor($trigger);
            $cronExpression = $trigger->getCronExpression();
            $conditions = self::decodeConditions($trigger->getConditions());

            if ($catalogCode === TriggerCatalog::LOW_STOCK && $input['threshold'] !== null) {
                $conditions['threshold'] = $input['threshold'];
            } elseif ($catalogCode === TriggerCatalog::CART_ABANDONED) {
                if ($input['delayHours'] !== null) {
                    $conditions['delay_hours'] = $input['delayHours'];
                }
                if ($input['minAmount'] !== null && $input['minAmount'] > 0) {
                    $conditions['min_amount'] = $input['minAmount'];
                } else {
                    unset($conditions['min_amount']);
                }
            } elseif ($catalogCode === TriggerCatalog::SCHEDULE && $input['time'] !== null) {
                $cronExpression = self::timeToCron($input['time']);
            }

            // "Plafond quotidien" (AC2) applies to whichever trigger is this skill's own
            // recognized business trigger -- never to an unrelated custom trigger the
            // expert wizard may have added, which this call leaves untouched otherwise.
            if ($catalogCode !== null) {
                if ($input['dailyCap'] !== null) {
                    $conditions['max_per_day'] = $input['dailyCap'];
                } else {
                    unset($conditions['max_per_day']);
                }
            }

            $data[] = [
                'type' => $trigger->getType(),
                'cronExpression' => $cronExpression,
                'eventName' => $trigger->getEventName(),
                'conditions' => $conditions === [] ? null : $conditions,
            ];
        }

        return $data;
    }

    /**
     * @return list<array{connectorCode: string, enabled: bool}>
     */
    private static function channelsData(int $agentDefinitionId): array
    {
        return array_map(
            static fn (AgentChannel $c): array => ['connectorCode' => $c->getConnectorCode(), 'enabled' => (bool) $c->getEnabled()],
            AgentChannelQuery::create()->filterByAgentDefinitionId($agentDefinitionId)->find()->getData(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeConditions(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{labelKey: string, icon: string, color: string} $meta
     *
     * @return array{code: string, labelKey: string, icon: string, color: string, state: string, agentDefinitionId: ?int, acceptanceRate: ?float, costPerProposalEur: ?float, editUrl: ?string, guidedSettings: ?array<string, mixed>}
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
            'guidedSettings' => $representative !== null ? $this->guidedSettingsFor($code, $representative) : null,
        ];
    }

    /**
     * Guided-edition card contract (MYO-508 AC2/AC3/AC4): built once per
     * activated skill from the real `agent_definition`/`agent_trigger` rows,
     * never from the preset defaults -- those only seeded the row once at
     * {@see self::activate()} time. `null` for the protected conversational
     * copilot (no guided edit button, spec signalement n°3) and for a skill
     * that was never activated (no row to read settings from -- `editUrl`
     * stays null too in that case, so the template never renders the button).
     *
     * @return ?array<string, mixed>
     */
    private function guidedSettingsFor(string $code, AgentDefinition $definition): ?array
    {
        if ($code === AgentDefinitionSeeder::MERCHANT_CODE) {
            return null;
        }

        $preset = AgentPresets::find($code);
        $variants = $preset['rolePromptVariants'] ?? [];
        $variantKind = self::variantKind($variants);
        $currentVariant = self::matchVariant((string) $definition->getRolePrompt(), $variants);

        $trigger = AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->findOne();
        $capabilities = $this->definitionManager->capabilitiesFor($definition->getId());

        return [
            'variantKind' => $variantKind,
            'variantOptions' => self::variantOptions($variantKind),
            'currentVariant' => $currentVariant,
            'drift' => $variantKind !== null && $currentVariant === null,
            'trigger' => $trigger !== null ? self::triggerSettings($trigger) : ['kind' => null],
            'dailyCap' => $trigger !== null ? TriggerConditions::option($trigger->getConditions(), 'max_per_day') : null,
            'monthlyBudgetEur' => $definition->getMonthlyBudgetUsd() !== null ? round((float) $definition->getMonthlyBudgetUsd() * ModelCatalog::usdToEurRate(), 2) : null,
            'isStagedChange' => self::anyStagedChange($capabilities, $this->capabilityCatalog),
            'saveUrl' => $this->urlGenerator->generate('commerceagents_skill_guided_settings', ['code' => $code]),
        ];
    }

    /**
     * @param array<string, string> $variants
     */
    private static function variantKind(array $variants): ?string
    {
        if ($variants === []) {
            return null;
        }

        return array_key_exists(AgentPresets::TONE_WARM, $variants) ? 'tone' : 'detail';
    }

    /**
     * Strict, normalized-text match against the preset's known variants
     * (AC4) -- never a fuzzy comparison, and never re-derived from the scope
     * sentences {@see ScopeCatalog} appends (those are write-only by design,
     * see that class's docblock).
     *
     * @param array<string, string> $variants
     */
    private static function matchVariant(string $rolePrompt, array $variants): ?string
    {
        $normalized = trim($rolePrompt);
        foreach ($variants as $key => $text) {
            if (trim($text) === $normalized) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, labelKey: string}>
     */
    private static function variantOptions(?string $kind): array
    {
        return match ($kind) {
            'tone' => [
                ['code' => AgentPresets::TONE_WARM, 'labelKey' => 'Warm'],
                ['code' => AgentPresets::TONE_NEUTRAL, 'labelKey' => 'Neutral and professional'],
                ['code' => AgentPresets::TONE_DIRECT, 'labelKey' => 'Direct and brief'],
            ],
            'detail' => [
                ['code' => AgentPresets::DETAIL_CONCISE, 'labelKey' => 'Concise'],
                ['code' => AgentPresets::DETAIL_DETAILED, 'labelKey' => 'Detailed'],
            ],
            default => [],
        };
    }

    /**
     * Current value of the skill's single business trigger, read from the
     * real `agent_trigger` row (never the preset default, which only seeded
     * it once) -- AC2's threshold/delay/schedule-time settings, never
     * concerned by the AC4 prompt-text drift (separate columns).
     *
     * @return array{kind: ?string, value?: int, min?: int, max?: int, minAmount?: int|float|string|null, time?: ?string}
     */
    private static function triggerSettings(AgentTrigger $trigger): array
    {
        $conditions = $trigger->getConditions();

        return match (TriggerCatalogMapping::catalogCodeFor($trigger)) {
            TriggerCatalog::LOW_STOCK => [
                'kind' => 'threshold',
                'value' => TriggerConditions::intOption($conditions, 'threshold', 5),
                'min' => 1,
                'max' => 50,
            ],
            TriggerCatalog::CART_ABANDONED => [
                'kind' => 'delay_hours',
                'value' => TriggerConditions::intOption($conditions, 'delay_hours', 24),
                'min' => 1,
                'max' => 72,
                'minAmount' => TriggerConditions::option($conditions, 'min_amount'),
            ],
            TriggerCatalog::SCHEDULE => [
                'kind' => 'schedule',
                'time' => self::cronToTime($trigger->getCronExpression()),
            ],
            default => ['kind' => null],
        };
    }

    private static function cronToTime(?string $cron): ?string
    {
        $parts = $cron !== null ? explode(' ', $cron) : [];
        if (\count($parts) < 2) {
            return null;
        }

        return \sprintf('%02d:%02d', (int) $parts[1], (int) $parts[0]);
    }

    /**
     * "Propose" vs "Applique automatiquement" badge (AC3): strictly derived
     * from {@see CapabilityCatalog::isStagedChange()} on the skill's actual
     * capabilities -- read-only here, never a setting of its own.
     *
     * @param list<string> $capabilities
     */
    private static function anyStagedChange(array $capabilities, CapabilityCatalog $catalog): bool
    {
        foreach ($capabilities as $capability) {
            if ($catalog->isStagedChange($capability)) {
                return true;
            }
        }

        return false;
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
