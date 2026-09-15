<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Model\AgentCapability;
use CommerceAgents\Model\AgentCapabilityQuery;
use CommerceAgents\Model\AgentChannel;
use CommerceAgents\Model\AgentChannelQuery;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentDefinitionQuery;
use CommerceAgents\Model\AgentRun;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Model\AgentTrigger;
use CommerceAgents\Model\AgentTriggerQuery;
use CommerceAgents\Service\Run\AgentRunQueue;
use CommerceAgents\Service\Run\CronSchedule;
use Propel\Runtime\ActiveQuery\Criteria;
use Psr\Log\LoggerInterface;

/**
 * Back-office CRUD for configurable agents (agent_definition + its
 * capabilities/triggers/channels, schema delivered by MYO-229). Read side
 * feeds the "Agents IA" list (plan MYO-227 §4.1); write side is the wizard
 * and edit screens (§4.3).
 */
final readonly class AgentDefinitionManager
{
    /** Channel connectors selectable in the wizard, matching ChannelConnectorRegistry codes (MYO-300). */
    public const CHANNEL_CONNECTORS = ['mail', 'mattermost', 'slack'];

    /** Connectors announced but not selectable yet (V2). */
    public const CHANNEL_CONNECTORS_SOON = ['telegram'];

    /** Server-side backstop for the role/override prompt field cap shown in the UI (MYO-280 §3). */
    public const MAX_ROLE_PROMPT_CHARS = 8000;

    public function __construct(
        private ModelCatalog $modelCatalog,
        private TriggerCatalog $triggerCatalog,
        private AgentRunQueue $runQueue,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * True since MYO-300 shipped the central channel connector settings panel:
     * the wizard step renders real channel checkboxes instead of the "coming
     * soon" placeholder. Settings themselves are configured once, centrally,
     * via ChannelConnectorConfigService — not per agent_channel row.
     */
    public function channelsAvailable(): bool
    {
        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSummaries(): array
    {
        $definitions = AgentDefinitionQuery::create()->orderById()->find();

        return array_map($this->toSummary(...), $definitions->getData());
    }

    public function count(): int
    {
        return AgentDefinitionQuery::create()->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(AgentDefinition $definition): array
    {
        $triggers = AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->find()->getData();
        $channels = AgentChannelQuery::create()->filterByAgentDefinitionId($definition->getId())->find()->getData();
        $lastRun = AgentRunQuery::create()
            ->filterByAgentDefinitionId($definition->getId())
            ->orderByCreatedAt(Criteria::DESC)
            ->findOne();

        return [
            'id' => $definition->getId(),
            'code' => $definition->getCode(),
            'title' => $definition->getTitle(),
            'description' => $definition->getDescription(),
            'enabled' => (bool) $definition->getEnabled(),
            'protected' => \in_array($definition->getCode(), AgentDefinitionSeeder::PROTECTED_CODES, true),
            'triggerChips' => array_map(fn (AgentTrigger $t): array => $this->triggerChip($t), $triggers),
            'channelChips' => array_map(fn (AgentChannel $c): array => $this->channelChip($c), $channels),
            'modelChip' => $this->modelChip($definition),
            'lastRun' => $lastRun !== null ? $this->lastRunSummary($lastRun) : null,
        ];
    }

    /**
     * The protected shopping/merchant assistants are agent_definition rows
     * too (AgentDefinitionSeeder): this is how the chat controllers reach
     * their editable override and memory (plan MYO-280 §1).
     */
    public function findByCode(string $code): ?AgentDefinition
    {
        return AgentDefinitionQuery::create()->filterByCode($code)->findOne();
    }

    public function find(int $id): ?AgentDefinition
    {
        return AgentDefinitionQuery::create()->findPk($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function findForEdit(int $id): array
    {
        $definition = AgentDefinitionQuery::create()->findPk($id);
        if ($definition === null) {
            throw new \RuntimeException(\sprintf('Agent %d not found', $id));
        }

        $triggers = AgentTriggerQuery::create()->filterByAgentDefinitionId($id)->find()->getData();
        $channels = AgentChannelQuery::create()->filterByAgentDefinitionId($id)->find()->getData();

        return [
            'definition' => $definition,
            'capabilities' => $this->capabilitiesFor($id),
            'triggers' => $triggers,
            'channels' => $channels,
        ];
    }

    /**
     * @return list<string>
     */
    public function capabilitiesFor(int $agentDefinitionId): array
    {
        return array_map(
            static fn (AgentCapability $c): string => $c->getCapability(),
            AgentCapabilityQuery::create()->filterByAgentDefinitionId($agentDefinitionId)->find()->getData(),
        );
    }

    /**
     * Creates or updates an agent definition with its capabilities, triggers
     * and channels, replacing the previous set of each (simplest model for a
     * form-driven admin screen; agent_run history is untouched).
     *
     * @param array{
     *     title: string, description: string, rolePrompt: string, model: string,
     *     monthlyBudgetUsd: ?float, enabled: bool,
     *     capabilities: list<string>,
     *     triggers: list<array{type: string, cronExpression?: ?string, eventName?: ?string, conditions?: ?array<string, mixed>}>,
     *     channels: list<array{connectorCode: string, enabled: bool}>,
     *     presetCode?: ?string,
     * } $data
     */
    public function save(?int $id, array $data): AgentDefinition
    {
        $definition = $id !== null ? AgentDefinitionQuery::create()->findPk($id) : null;
        if ($id !== null && $definition === null) {
            throw new \RuntimeException(\sprintf('Agent %d not found', $id));
        }

        $isNew = $definition === null;
        if ($isNew) {
            $definition = new AgentDefinition();
            $definition->setCode($this->generateCode($data['title']));
            // Recorded once at creation so "Reset to preset" (MYO-280 §1) knows
            // which AgentPresets entry to restore, whatever is typed afterwards.
            $presetCode = $data['presetCode'] ?? null;
            $definition->setPresetCode($presetCode !== null && AgentPresets::find($presetCode) !== null ? $presetCode : null);
        }

        $definition
            ->setTitle($data['title'])
            ->setDescription($data['description'])
            ->setRolePrompt(mb_substr($data['rolePrompt'], 0, self::MAX_ROLE_PROMPT_CHARS))
            ->setModel($data['model'] !== '' ? $data['model'] : null)
            ->setProvider($data['model'] !== '' ? 'mistral' : null)
            ->setMonthlyBudgetUsd($data['monthlyBudgetUsd'] !== null ? (string) $data['monthlyBudgetUsd'] : null)
            ->setEnabled($data['enabled'] ? 1 : 0);
        $definition->save();

        $this->replaceCapabilities($definition, $data['capabilities']);
        $this->replaceTriggers($definition, $data['triggers']);
        $this->replaceChannels($definition, $data['channels']);

        return $definition;
    }

    public function toggle(int $id): AgentDefinition
    {
        $definition = $this->requireDefinition($id);
        $definition->setEnabled($definition->getEnabled() ? 0 : 1)->save();

        return $definition;
    }

    public function delete(int $id): void
    {
        $definition = $this->requireDefinition($id);
        if (\in_array($definition->getCode(), AgentDefinitionSeeder::PROTECTED_CODES, true)) {
            throw new \RuntimeException('This assistant is built-in and cannot be deleted.');
        }
        $definition->delete();
    }

    public function runNow(int $id, ?int $adminId): AgentRun
    {
        return $this->runQueue->enqueueManual($this->requireDefinition($id), [], $adminId);
    }

    private function requireDefinition(int $id): AgentDefinition
    {
        $definition = AgentDefinitionQuery::create()->findPk($id);
        if ($definition === null) {
            throw new \RuntimeException(\sprintf('Agent %d not found', $id));
        }

        return $definition;
    }

    /**
     * @param list<string> $capabilities
     */
    private function replaceCapabilities(AgentDefinition $definition, array $capabilities): void
    {
        AgentCapabilityQuery::create()->filterByAgentDefinitionId($definition->getId())->delete();
        foreach (array_unique($capabilities) as $capability) {
            (new AgentCapability())
                ->setAgentDefinitionId($definition->getId())
                ->setCapability($capability)
                ->save();
        }
    }

    /**
     * @param list<array{type: string, cronExpression?: ?string, eventName?: ?string, conditions?: ?array<string, mixed>}> $triggers
     */
    private function replaceTriggers(AgentDefinition $definition, array $triggers): void
    {
        AgentTriggerQuery::create()->filterByAgentDefinitionId($definition->getId())->delete();
        $now = new \DateTimeImmutable();
        foreach ($triggers as $trigger) {
            $cronExpression = $trigger['cronExpression'] ?? null;
            (new AgentTrigger())
                ->setAgentDefinitionId($definition->getId())
                ->setType($trigger['type'])
                ->setEventName($trigger['eventName'] ?? null)
                ->setCronExpression($cronExpression)
                ->setConditions(isset($trigger['conditions']) ? json_encode($trigger['conditions'], \JSON_THROW_ON_ERROR) : null)
                ->setEnabled(1)
                ->setNextRunAt($this->nextRunAt($cronExpression, $now))
                ->save();
        }
    }

    /**
     * Seeds next_run_at at creation (and on every cron_expression change,
     * since replaceTriggers() always recreates the row) so a fresh cron
     * trigger is picked up by AgentRunQueue::enqueueDueCronRuns() on the next
     * drain instead of staying NULL forever (MYO-304). Same computation
     * AgentRunQueue::advanceSchedule() uses to roll the schedule forward.
     */
    private function nextRunAt(?string $cronExpression, \DateTimeImmutable $now): ?\DateTime
    {
        if ($cronExpression === null) {
            return null;
        }

        try {
            $next = CronSchedule::nextRunDate($cronExpression, $now);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->error('[commerce-agents] cron trigger unscheduled: '.$exception->getMessage(), [
                'cron_expression' => $cronExpression,
            ]);

            return null;
        }

        return $next !== null ? \DateTime::createFromImmutable($next) : null;
    }

    /**
     * @param list<array{connectorCode: string, enabled: bool}> $channels
     */
    private function replaceChannels(AgentDefinition $definition, array $channels): void
    {
        AgentChannelQuery::create()->filterByAgentDefinitionId($definition->getId())->delete();
        foreach ($channels as $channel) {
            (new AgentChannel())
                ->setAgentDefinitionId($definition->getId())
                ->setConnectorCode($channel['connectorCode'])
                ->setEnabled($channel['enabled'] ? 1 : 0)
                ->setMode('draft')
                ->save();
        }
    }

    private function generateCode(string $title): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $title), '_'));
        $slug = $slug !== '' ? $slug : 'agent';
        $code = $slug;
        $suffix = 1;
        while (AgentDefinitionQuery::create()->filterByCode($code)->exists()) {
            $code = $slug.'_'.++$suffix;
        }

        return $code;
    }

    /**
     * @return array{icon: string, label: string}
     */
    private function triggerChip(AgentTrigger $trigger): array
    {
        if ($trigger->getType() === 'cron') {
            return ['icon' => 'bi-clock', 'label' => $trigger->getCronExpression() ?? $this->triggerCatalog->label(TriggerCatalog::SCHEDULE)];
        }

        return ['icon' => $this->triggerCatalog->icon((string) $trigger->getEventName()), 'label' => $this->triggerCatalog->label((string) $trigger->getEventName())];
    }

    /**
     * @return array{icon: string, label: string}
     */
    private function channelChip(AgentChannel $channel): array
    {
        $icons = ['mail' => 'bi-envelope', 'mattermost' => 'bi-chat-square-dots', 'slack' => 'bi-slack', 'telegram' => 'bi-telegram'];
        $labels = ['mail' => 'E-mail', 'mattermost' => 'Mattermost', 'slack' => 'Slack', 'telegram' => 'Telegram'];
        $code = $channel->getConnectorCode();

        return ['icon' => $icons[$code] ?? 'bi-broadcast', 'label' => $labels[$code] ?? $code];
    }

    /**
     * @return ?array{icon: string, label: string}
     */
    private function modelChip(AgentDefinition $definition): ?array
    {
        $modelId = $definition->getModel();
        if ($modelId === null || $modelId === '') {
            return null;
        }

        $model = $this->modelCatalog->find('mistral', $modelId);

        return ['icon' => 'bi-cpu', 'label' => $model !== null ? ($model->getName() ?: $modelId) : $modelId];
    }

    /**
     * @return array{status: string, icon: string, colorClass: string, at: ?\DateTimeInterface}
     */
    private function lastRunSummary(AgentRun $run): array
    {
        $icons = [
            AgentRunQueue::STATUS_DONE => ['bi-check-circle', 'text-success'],
            AgentRunQueue::STATUS_FAILED => ['bi-exclamation-triangle', 'text-danger'],
            AgentRunQueue::STATUS_SKIPPED_BUDGET => ['bi-exclamation-triangle', 'text-danger'],
            AgentRunQueue::STATUS_RUNNING => ['bi-arrow-repeat', 'text-muted'],
            AgentRunQueue::STATUS_QUEUED => ['bi-hourglass-split', 'text-muted'],
        ];
        [$icon, $colorClass] = $icons[$run->getStatus()] ?? ['bi-question-circle', 'text-muted'];

        return [
            'status' => $run->getStatus(),
            'icon' => $icon,
            'colorClass' => $colorClass,
            'at' => $run->getFinishedAt() ?? $run->getStartedAt() ?? $run->getCreatedAt(),
        ];
    }
}
