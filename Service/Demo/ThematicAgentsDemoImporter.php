<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Demo;

use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Service\AgentDefinitionManager;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\ModuleAvailabilityInterface;
use CommerceAgents\Service\Run\TriggerCatalogMapping;
use Thelia\Command\Import\DemoImportContext;
use Thelia\Command\Import\DemoImporterInterface;

/**
 * `bin/install --with-demo` never created the two thematic agents expected
 * by tests/Playwright/recette-v1 (plan MYO-437/439): this importer replays
 * the same preset -> AgentDefinitionManager::save() pipeline the admin
 * wizard uses for "Abandoned cart relaunch" and "Welcome new customers"
 * (AgentsController::new()/save(), preset lookup via AgentPresets::find()),
 * so the technical trigger mapping stays the single source of truth in
 * TriggerCatalogMapping instead of being re-guessed here.
 */
final readonly class ThematicAgentsDemoImporter implements DemoImporterInterface
{
    private const PRESET_CODES = [AgentPresets::CART_ABANDONED, AgentPresets::WELCOME_NEW_CUSTOMER];

    public function __construct(
        private ModuleAvailabilityInterface $moduleAvailability,
        private AgentDefinitionManager $agentManager,
    ) {
    }

    public function priority(): int
    {
        return 150;
    }

    public function description(): string
    {
        return 'CommerceAgents thematic agents';
    }

    public function import(DemoImportContext $context): void
    {
        // A demo import can run against a site where CommerceAgents was
        // never activated (it is not part of the core `bin/install` module
        // set, MYO-379) -- skip silently rather than fail the whole import.
        if (!$this->moduleAvailability->isActive('CommerceAgents')) {
            return;
        }

        foreach (self::PRESET_CODES as $presetCode) {
            $this->importPreset($presetCode);
        }
    }

    /**
     * Idempotent: a preset already represented by an agent_definition row is
     * left untouched, whether that row came from a previous demo import or
     * from a merchant using the wizard themselves.
     */
    private function importPreset(string $presetCode): void
    {
        if (AgentDefinitionQuery::create()->filterByPresetCode($presetCode)->exists()) {
            return;
        }

        $preset = AgentPresets::find($presetCode);
        if ($preset === null) {
            return;
        }

        $this->agentManager->save(null, [
            'title' => $preset['title'],
            'description' => $preset['subtitle'],
            'rolePrompt' => $preset['rolePrompt'],
            'presetCode' => $presetCode,
            'model' => '',
            'provider' => null,
            'monthlyBudgetUsd' => null,
            'enabled' => true,
            'capabilities' => $preset['capabilities'],
            'triggers' => array_map(self::technicalTrigger(...), $preset['triggers']),
            'channels' => $preset['channel'] !== null ? [['connectorCode' => $preset['channel'], 'enabled' => true]] : [],
        ]);
    }

    /**
     * Same trigger shape AgentsController::parseTriggers() builds for the
     * wizard's "cart abandoned" (delay_hours) and "new customer" (no
     * conditions) checkboxes.
     *
     * @param array{type: string, hours?: int, time?: string} $trigger
     *
     * @return array{type: string, cronExpression: ?string, eventName: ?string, conditions: ?array<string, mixed>}
     */
    private static function technicalTrigger(array $trigger): array
    {
        return TriggerCatalogMapping::technicalFor($trigger['type'])
            + ['conditions' => isset($trigger['hours']) ? ['delay_hours' => $trigger['hours']] : null];
    }
}
