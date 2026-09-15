<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Hook\Admin\AdminHookManager;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Model\AgentMemory;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\AgentDefinitionManager;
use CommerceAgents\Service\AgentDefinitionSeeder;
use CommerceAgents\Service\AgentMemoryManager;
use CommerceAgents\Service\AgentPresets;
use CommerceAgents\Service\CapabilityCatalog;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\SystemPromptFactory;
use CommerceAgents\Service\TriggerCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Domain\Order\Service\OrderStatusCatalog;
use Twig\Environment;

/**
 * "Agents IA" screen (plan MYO-227 §4): card list + empty state, and the
 * create/edit form (wizard on create, plain sections on edit, §4.3).
 */
final readonly class AgentsController
{
    public function __construct(
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private AgentDefinitionManager $agentManager,
        private AgentMemoryManager $memoryManager,
        private SystemPromptFactory $systemPromptFactory,
        private AgentConfigService $configService,
        private AssistantLocaleResolver $localeResolver,
        private ModelCatalog $modelCatalog,
        private CapabilityCatalog $capabilityCatalog,
        private TriggerCatalog $triggerCatalog,
        private OrderStatusCatalog $orderStatusCatalog,
        private Translator $translator,
        private Environment $twig,
    ) {
    }

    #[Route('/admin/module/CommerceAgents/agents', name: 'commerceagents_agents_page', methods: ['GET'])]
    public function list(): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $agents = $this->agentManager->listSummaries();

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/agents/list.html.twig', [
            'agents' => $agents,
            'presets' => self::presetsForDisplay($this->translator),
            'newAgentUrl' => $this->urlGenerator->generate('commerceagents_agents_new'),
            'merchantPageUrl' => $this->urlGenerator->generate('commerceagents_merchant_page'),
            'csrfToken' => $this->csrfTokenManager->getToken(AdminHookManager::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    #[Route('/admin/module/CommerceAgents/agents/new', name: 'commerceagents_agents_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $presetCode = (string) $request->query->get('preset', '');
        $preset = $presetCode !== '' ? AgentPresets::find($presetCode) : null;

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/agents/form.html.twig', $this->formViewData(
            mode: 'wizard',
            agentId: 0,
            title: $preset !== null ? $this->translator->trans($preset['title'], [], 'commerceagents') : '',
            description: $preset !== null ? $this->translator->trans($preset['subtitle'], [], 'commerceagents') : '',
            rolePrompt: $preset !== null ? $preset['rolePrompt'] : '',
            model: $this->defaultModelForTier($preset !== null ? $preset['tier'] : null),
            monthlyBudgetUsd: null,
            enabled: true,
            capabilities: $preset !== null ? $preset['capabilities'] : [],
            triggers: $preset !== null ? $preset['triggers'] : [],
            channels: $preset !== null && $preset['channel'] !== null ? [$preset['channel']] : [],
            startAtStep: $preset !== null ? 2 : 1,
            presetCode: $presetCode !== '' ? $presetCode : null,
        )));
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/edit', name: 'commerceagents_agents_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $data = $this->agentManager->findForEdit($id);
        /** @var AgentDefinition $definition */
        $definition = $data['definition'];

        $triggers = [];
        foreach ($data['triggers'] as $trigger) {
            $conditions = $trigger->getConditions() !== null ? json_decode($trigger->getConditions(), true) : [];
            $triggers[] = [
                'type' => $trigger->getType() === 'cron' ? TriggerCatalog::SCHEDULE : $trigger->getEventName(),
                'hours' => $conditions['hours'] ?? null,
                'time' => $trigger->getCronExpression() !== null ? self::cronToTime($trigger->getCronExpression()) : null,
                'orderStatusIds' => $conditions['order_status_ids'] ?? [],
                'threshold' => $conditions['threshold'] ?? null,
            ];
        }

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/agents/form.html.twig', $this->formViewData(
            mode: 'edit',
            agentId: $id,
            title: $definition->getTitle(),
            description: (string) $definition->getDescription(),
            rolePrompt: (string) $definition->getRolePrompt(),
            model: (string) $definition->getModel(),
            monthlyBudgetUsd: $definition->getMonthlyBudgetUsd() !== null ? (float) $definition->getMonthlyBudgetUsd() : null,
            enabled: (bool) $definition->getEnabled(),
            capabilities: $data['capabilities'],
            triggers: $triggers,
            channels: array_map(static fn ($c): string => $c->getConnectorCode(), $data['channels']),
            startAtStep: null,
            protected: \in_array($definition->getCode(), AgentDefinitionSeeder::PROTECTED_CODES, true),
            definition: $definition,
        )));
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/memory/add', name: 'commerceagents_agents_memory_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addMemory(int $id, Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::UPDATE)) {
            return $denied;
        }

        $content = trim((string) $request->request->get('content', ''));
        if ($content !== '') {
            $this->memoryManager->create($id, $content, AgentMemoryManager::SOURCE_MANUAL);
        }

        return $this->redirectToMemoryTab($id);
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/memory/{memoryId}/update', name: 'commerceagents_agents_memory_update', requirements: ['id' => '\d+', 'memoryId' => '\d+'], methods: ['POST'])]
    public function updateMemory(int $id, int $memoryId, Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::UPDATE)) {
            return $denied;
        }

        $content = trim((string) $request->request->get('content', ''));
        if ($content !== '') {
            try {
                $this->memoryManager->update($id, $memoryId, $content);
            } catch (\RuntimeException) {
                // Stale/forged id: the row is gone or belongs to another agent — ignored, same as delete().
            }
        }

        return $this->redirectToMemoryTab($id);
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/memory/{memoryId}/toggle', name: 'commerceagents_agents_memory_toggle', requirements: ['id' => '\d+', 'memoryId' => '\d+'], methods: ['POST'])]
    public function toggleMemory(int $id, int $memoryId, Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::UPDATE)) {
            return $denied;
        }

        try {
            $this->memoryManager->toggle($id, $memoryId);
        } catch (\RuntimeException) {
        }

        return $this->redirectToMemoryTab($id);
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/memory/{memoryId}/delete', name: 'commerceagents_agents_memory_delete', requirements: ['id' => '\d+', 'memoryId' => '\d+'], methods: ['POST'])]
    public function deleteMemory(int $id, int $memoryId, Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::DELETE)) {
            return $denied;
        }

        try {
            $this->memoryManager->delete($id, $memoryId);
        } catch (\RuntimeException) {
        }

        return $this->redirectToMemoryTab($id);
    }

    private function redirectToMemoryTab(int $id): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('commerceagents_agents_edit', ['id' => $id]).'#agent-memory');
    }

    #[Route('/admin/module/CommerceAgents/agents/save', name: 'commerceagents_agents_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::UPDATE)) {
            return $denied;
        }

        $id = (int) $request->request->get('agent_id', 0);
        $eurBudget = trim((string) $request->request->get('monthly_budget_eur', ''));
        $submitAction = (string) $request->request->get('submit_action', 'keep');

        $definition = $this->agentManager->save($id > 0 ? $id : null, [
            'title' => trim((string) $request->request->get('title')) ?: 'Nouvel agent',
            'description' => trim((string) $request->request->get('description', '')),
            'rolePrompt' => (string) $request->request->get('role_prompt', ''),
            'presetCode' => (string) $request->request->get('preset_code', '') ?: null,
            'model' => (string) $request->request->get('model', ''),
            'monthlyBudgetUsd' => $eurBudget !== '' ? ModelCatalog::toUsd((float) str_replace(',', '.', $eurBudget), 'EUR') : null,
            'enabled' => $submitAction === 'pause' ? false : ($submitAction === 'activate' || $request->request->get('enabled') === '1'),
            'capabilities' => array_values(array_intersect((array) $request->request->all('capabilities'), array_column($this->capabilityCatalog->all(), 'code'))),
            'triggers' => $this->parseTriggers($request),
            'channels' => $this->parseChannels($request),
        ]);

        return new RedirectResponse($this->urlGenerator->generate('commerceagents_agents_edit', ['id' => $definition->getId()]));
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/toggle', name: 'commerceagents_agents_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggle(int $id, Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::UPDATE)) {
            return $denied;
        }

        $definition = $this->agentManager->toggle($id);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true, 'enabled' => (bool) $definition->getEnabled()]);
        }

        return new RedirectResponse($this->urlGenerator->generate('commerceagents_agents_page'));
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/run-now', name: 'commerceagents_agents_run_now', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function runNow(int $id, Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::UPDATE)) {
            return $denied;
        }

        $admin = $this->securityContext->getAdminUser();
        $this->agentManager->runNow($id, $admin?->getId());

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        return new RedirectResponse($this->urlGenerator->generate('commerceagents_agents_page'));
    }

    #[Route('/admin/module/CommerceAgents/agents/{id}/delete', name: 'commerceagents_agents_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if ($denied = $this->guard($request, AccessManager::DELETE)) {
            return $denied;
        }

        try {
            $this->agentManager->delete($id);
        } catch (\RuntimeException) {
            // Protected assistant: the UI never renders a delete action for
            // it, so reaching here means a stale/forged request — ignored.
        }

        return new RedirectResponse($this->urlGenerator->generate('commerceagents_agents_page'));
    }

    private function guard(Request $request, string $access): ?Response
    {
        if ($denied = $this->access->check([], 'commerceagents', $access)) {
            return $denied;
        }

        $token = new CsrfToken(AdminHookManager::CSRF_TOKEN_ID, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @return list<array{type: string, cronExpression: ?string, eventName: ?string, conditions: ?array<string, mixed>}>
     */
    private function parseTriggers(Request $request): array
    {
        $triggers = [];

        if ($request->request->get('trigger_cart_abandoned') === '1') {
            $triggers[] = ['type' => 'event', 'eventName' => TriggerCatalog::CART_ABANDONED, 'conditions' => ['hours' => max(1, (int) $request->request->get('trigger_cart_abandoned_hours', 24))]];
        }
        if ($request->request->get('trigger_new_order') === '1') {
            $triggers[] = ['type' => 'event', 'eventName' => TriggerCatalog::NEW_ORDER, 'conditions' => null];
        }
        if ($request->request->get('trigger_order_status_change') === '1') {
            $ids = array_values(array_filter(array_map('intval', (array) $request->request->all('trigger_order_status_ids'))));
            $triggers[] = ['type' => 'event', 'eventName' => TriggerCatalog::ORDER_STATUS_CHANGE, 'conditions' => ['order_status_ids' => $ids]];
        }
        if ($request->request->get('trigger_new_customer') === '1') {
            $triggers[] = ['type' => 'event', 'eventName' => TriggerCatalog::NEW_CUSTOMER, 'conditions' => null];
        }
        if ($request->request->get('trigger_low_stock') === '1') {
            $triggers[] = ['type' => 'event', 'eventName' => TriggerCatalog::LOW_STOCK, 'conditions' => ['threshold' => max(0, (int) $request->request->get('trigger_low_stock_threshold', 5))]];
        }
        if ($request->request->get('trigger_schedule') === '1') {
            $time = (string) $request->request->get('trigger_schedule_time', '09:00');
            $triggers[] = ['type' => 'cron', 'cronExpression' => self::timeToCron($time), 'conditions' => null];
        }

        return $triggers;
    }

    /**
     * @return list<array{connectorCode: string, enabled: bool}>
     */
    private function parseChannels(Request $request): array
    {
        $channels = [];
        foreach (AgentDefinitionManager::CHANNEL_CONNECTORS as $connectorCode) {
            if ($request->request->get('channel_'.$connectorCode) === '1') {
                $channels[] = ['connectorCode' => $connectorCode, 'enabled' => true];
            }
        }

        return $channels;
    }

    private static function timeToCron(string $time): string
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return \sprintf('%d %d * * *', (int) $minute, (int) $hour);
    }

    private static function cronToTime(string $cron): ?string
    {
        $parts = explode(' ', $cron);
        if (\count($parts) < 2) {
            return null;
        }

        return \sprintf('%02d:%02d', (int) $parts[1], (int) $parts[0]);
    }

    private function defaultModelForTier(?string $tier): string
    {
        if ($tier === null) {
            return '';
        }
        foreach ($this->modelCatalog->getSelectableModels('mistral') as $choice) {
            if ($choice->tier === $tier) {
                return $choice->modelId;
            }
        }

        return '';
    }

    /**
     * @param list<string>               $capabilities
     * @param list<array<string, mixed>> $triggers
     * @param list<string>               $channels
     *
     * @return array<string, mixed>
     */
    private function formViewData(
        string $mode,
        int $agentId,
        string $title,
        string $description,
        string $rolePrompt,
        string $model,
        ?float $monthlyBudgetUsd,
        bool $enabled,
        array $capabilities,
        array $triggers,
        array $channels,
        ?int $startAtStep,
        bool $protected = false,
        ?string $presetCode = null,
        ?AgentDefinition $definition = null,
    ): array {
        $modelChoices = array_map(static fn ($c): array => (array) $c, $this->modelCatalog->getSelectableModels('mistral'));
        $shopDefault = null;
        foreach ($modelChoices as $choice) {
            if ($choice['isDefault']) {
                $shopDefault = $choice;
                break;
            }
        }

        $orderStatuses = array_map(
            static fn ($status): array => ['id' => $status->getId(), 'title' => $status->getTitle()],
            $this->orderStatusCatalog->all(),
        );

        $eurBudget = $monthlyBudgetUsd !== null ? round($monthlyBudgetUsd * ModelCatalog::usdToEurRate(), 2) : null;

        $triggersByType = [
            TriggerCatalog::CART_ABANDONED => ['checked' => false, 'hours' => 24],
            TriggerCatalog::NEW_ORDER => ['checked' => false],
            TriggerCatalog::ORDER_STATUS_CHANGE => ['checked' => false, 'orderStatusIds' => []],
            TriggerCatalog::NEW_CUSTOMER => ['checked' => false],
            TriggerCatalog::LOW_STOCK => ['checked' => false, 'threshold' => 5],
            TriggerCatalog::SCHEDULE => ['checked' => false, 'time' => '09:00'],
        ];
        foreach ($triggers as $trigger) {
            if (!isset($triggersByType[$trigger['type']])) {
                continue;
            }
            $triggersByType[$trigger['type']]['checked'] = true;
            if (!empty($trigger['hours'])) {
                $triggersByType[$trigger['type']]['hours'] = (int) $trigger['hours'];
            }
            if (!empty($trigger['time'])) {
                $triggersByType[$trigger['type']]['time'] = $trigger['time'];
            }
            if (!empty($trigger['orderStatusIds'])) {
                $triggersByType[$trigger['type']]['orderStatusIds'] = $trigger['orderStatusIds'];
            }
            if (!empty($trigger['threshold'])) {
                $triggersByType[$trigger['type']]['threshold'] = (int) $trigger['threshold'];
            }
        }

        $channelsByCode = [];
        foreach (AgentDefinitionManager::CHANNEL_CONNECTORS as $connectorCode) {
            $channelsByCode[$connectorCode] = \in_array($connectorCode, $channels, true);
        }

        [$effectivePrompt, $memoryEntries, $memoryCoverage, $resetRolePromptTo] = $definition !== null
            ? $this->promptPreviewData($definition, $rolePrompt, $agentId)
            : [null, [], null, ''];

        return [
            'mode' => $mode,
            'agentId' => $agentId,
            'protected' => $protected,
            'values' => [
                'title' => $title,
                'description' => $description,
                'rolePrompt' => $rolePrompt,
                'model' => $model,
                'monthlyBudgetEur' => $eurBudget,
                'enabled' => $enabled,
                'capabilities' => $capabilities,
                'triggers' => $triggersByType,
                'channels' => $channelsByCode,
            ],
            'startAtStep' => $startAtStep,
            'presets' => self::presetsForDisplay($this->translator),
            'modelChoices' => $modelChoices,
            'shopDefaultChoice' => $shopDefault,
            'modelPricedAt' => $this->modelCatalog->latestPricedAt('mistral')?->format('d/m/Y') ?? (new \DateTimeImmutable())->format('d/m/Y'),
            'orderStatuses' => $orderStatuses,
            'capabilitiesCatalog' => $this->capabilityCatalog->all(),
            'triggerCatalog' => $this->triggerCatalog->all(),
            'channelsAvailable' => $this->agentManager->channelsAvailable(),
            'channelConnectors' => AgentDefinitionManager::CHANNEL_CONNECTORS,
            'channelConnectorsSoon' => AgentDefinitionManager::CHANNEL_CONNECTORS_SOON,
            'saveUrl' => $this->urlGenerator->generate('commerceagents_agents_save'),
            'listUrl' => $this->urlGenerator->generate('commerceagents_agents_page'),
            'csrfToken' => $this->csrfTokenManager->getToken(AdminHookManager::CSRF_TOKEN_ID)->getValue(),
            'presetCode' => $presetCode,
            'maxRolePromptChars' => AgentDefinitionManager::MAX_ROLE_PROMPT_CHARS,
            'maxMemoryChars' => AgentMemoryManager::MAX_ENTRY_CHARS,
            'resetRolePromptTo' => $resetRolePromptTo,
            'effectivePrompt' => $effectivePrompt,
            'memoryEntries' => $memoryEntries,
            'memoryCoverage' => $memoryCoverage,
            'memoryAddUrl' => $agentId > 0 ? $this->urlGenerator->generate('commerceagents_agents_memory_add', ['id' => $agentId]) : null,
        ];
    }

    /**
     * Everything the "Effective system prompt" preview and the "Memory" tab
     * need: assembled with the exact same SystemPromptFactory + cap logic the
     * runtime uses, so the preview never lies (MYO-280 §1 acceptance
     * criterion: "voir l'effet sur l'aperçu").
     *
     * @return array{0: string, 1: list<array<string, mixed>>, 2: array{includedCount: int, totalCount: int, droppedCount: int}, 3: string}
     */
    private function promptPreviewData(AgentDefinition $definition, string $pendingRolePrompt, int $agentId): array
    {
        $locale = $this->localeResolver->forAgentRun();
        $activeMemory = $this->memoryManager->activeContents($agentId);

        $effectivePrompt = match ($definition->getCode()) {
            AgentDefinitionSeeder::SHOPPING_CODE => $this->systemPromptFactory->shopping($this->configService->getAssistantName(), $locale, $pendingRolePrompt, $activeMemory),
            AgentDefinitionSeeder::MERCHANT_CODE => $this->systemPromptFactory->merchant($locale, $pendingRolePrompt, $activeMemory),
            default => $this->systemPromptFactory->agent($definition->getTitle(), $pendingRolePrompt, $locale, $activeMemory),
        };

        $memoryEntries = array_map(fn (AgentMemory $entry): array => [
            'id' => $entry->getId(),
            'content' => (string) $entry->getContent(),
            'source' => $entry->getSource(),
            'enabled' => (bool) $entry->getEnabled(),
            'createdAt' => $entry->getCreatedAt(),
            'toggleUrl' => $this->urlGenerator->generate('commerceagents_agents_memory_toggle', ['id' => $agentId, 'memoryId' => $entry->getId()]),
            'updateUrl' => $this->urlGenerator->generate('commerceagents_agents_memory_update', ['id' => $agentId, 'memoryId' => $entry->getId()]),
            'deleteUrl' => $this->urlGenerator->generate('commerceagents_agents_memory_delete', ['id' => $agentId, 'memoryId' => $entry->getId()]),
        ], $this->memoryManager->listForAgent($agentId));

        $memoryCoverage = $this->memoryManager->promptCoverage($agentId);

        $isProtected = \in_array($definition->getCode(), AgentDefinitionSeeder::PROTECTED_CODES, true);
        $preset = !$isProtected && $definition->getPresetCode() !== null ? AgentPresets::find($definition->getPresetCode()) : null;
        $resetRolePromptTo = $preset['rolePrompt'] ?? '';

        return [$effectivePrompt, $memoryEntries, $memoryCoverage, $resetRolePromptTo];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function presetsForDisplay(Translator $translator): array
    {
        return array_map(static function (array $preset) use ($translator): array {
            $preset['title'] = $translator->trans($preset['title'], [], 'commerceagents');
            $preset['subtitle'] = $translator->trans($preset['subtitle'], [], 'commerceagents');

            return $preset;
        }, array_values(AgentPresets::all()));
    }
}
