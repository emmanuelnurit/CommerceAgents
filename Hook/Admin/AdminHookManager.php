<?php

declare(strict_types=1);

namespace CommerceAgents\Hook\Admin;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Model\AgentModel;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\BudgetGuard;
use CommerceAgents\Service\BudgetStatus;
use CommerceAgents\Service\Channel\ChannelConnectorConfigService;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\ModelChoice;
use CommerceAgents\Service\TokenUsageRepository;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\Parser\ParserResolver;
use Twig\Environment;

class AdminHookManager extends BaseHook
{
    public const CSRF_TOKEN_ID = 'commerceagents_config';

    private const PROVIDER_LABELS = [
        'anthropic' => 'Anthropic',
        'mistral' => 'Mistral AI',
        'openai-compatible' => 'OpenAI-compatible (OpenAI, OpenRouter…)',
    ];

    private const DEFAULT_BASE_URLS = [
        'anthropic' => 'https://api.anthropic.com',
        'mistral' => 'https://api.mistral.ai',
        'openai-compatible' => 'https://api.openai.com',
    ];

    /**
     * Native-language labels for the locales the module actually ships translations
     * for (cf. {@see coveredLocales()}). Falls back to the raw locale code for any
     * covered locale missing from this map, so adding an I18n/*.php file never breaks
     * the banner — it just shows a less friendly label until this map is updated.
     */
    private const LOCALE_LABELS = [
        'en_US' => 'English',
        'es_ES' => 'español',
        'fr_FR' => 'français',
        'it_IT' => 'italiano',
    ];

    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly AgentConfigService $configService,
        private readonly TokenUsageRepository $tokenUsageRepository,
        private readonly ModelCatalog $modelCatalog,
        private readonly BudgetGuard $budgetGuard,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly ChannelConnectorRegistry $channelRegistry,
        private readonly ChannelConnectorConfigService $channelConfig,
        private readonly RequestStack $requestStack,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'main.in-top-menu-items' => [['type' => 'back', 'method' => 'onMainTopMenuItems']],
            'module.configuration' => [['type' => 'back', 'method' => 'onModuleConfiguration']],
            'module.config-js' => [['type' => 'back', 'method' => 'onModuleConfigJs']],
            'main.footer-js' => [['type' => 'back', 'method' => 'onMainFooterJs']],
            'main.before-content' => [['type' => 'back', 'method' => 'onMainBeforeContent']],
            'main.topbar-top' => [['type' => 'back', 'method' => 'onMainTopbarTop']],
        ];
    }

    /**
     * MYO-485: agent notification center (icon + badge + dropdown), anchored
     * by the theme's own {@see \safe_hook('main.topbar-top')} in
     * `_top_nav.html.twig` -- that file is never touched, this listener is
     * the only thing that makes the anchor render something. Renders on
     * every back-office screen (not gated by {@see isCommerceAgentsScreen()}
     * like the locale banner), so its i18n rides the theme's own 21-locale
     * `translations/messages.<locale>.php` catalog (bare `|trans`, no
     * `'commerceagents'` domain) instead of the module's 4-locale one --
     * see the Twig template.
     */
    public function onMainTopbarTop(HookRenderEvent $event): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [], ['commerceagents'], [AccessManager::VIEW])) {
            return;
        }

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/notification-center.html.twig', [
            'summaryUrl' => $this->urlGenerator->generate('commerceagents_notifications'),
            'ackUrl' => $this->urlGenerator->generate('commerceagents_notifications_ack'),
            'briefUrl' => $this->urlGenerator->generate('commerceagents_changes'),
        ]));
    }

    /**
     * MYO-435: the BO manages 21 locales, CommerceAgents ships translations for 4
     * (cf. {@see coveredLocales()}). Outside those 4, every module screen silently
     * falls back to English with no signal — flagged as not acceptable during the
     * MYO-430 UX review. Fired from the single hook point common to every module
     * screen (`main.before-content` in base.html.twig) rather than patched into each
     * template, so new screens inherit the banner for free.
     */
    public function onMainBeforeContent(HookRenderEvent $event): void
    {
        if (!$this->isCommerceAgentsScreen()) {
            return;
        }

        if (!$this->securityContext->isGranted(['ADMIN'], [], ['commerceagents'], [AccessManager::VIEW])) {
            return;
        }

        $locale = $this->securityContext->getAdminUser()?->getLocale();
        $covered = $this->coveredLocales();

        if (null === $locale || \in_array($locale, $covered, true)) {
            return;
        }

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/locale-fallback-banner.html.twig', [
            'locale' => $locale,
            'availableLanguages' => implode(', ', array_map(
                static fn (string $code): string => self::LOCALE_LABELS[$code] ?? $code,
                $covered,
            )),
        ]));
    }

    /**
     * True on every full-page CommerceAgents screen: its own routes (all named
     * `commerceagents_*`) plus the generic Thelia module-configuration page
     * (`admin.module.configure`) when it targets this module specifically — that
     * route is shared by every module, so it needs the extra `module_code` check.
     */
    private function isCommerceAgentsScreen(): bool
    {
        $route = $this->requestStack->getCurrentRequest()?->attributes->get('_route');

        if (!\is_string($route)) {
            return false;
        }

        if (str_starts_with($route, 'commerceagents_')) {
            return true;
        }

        return 'admin.module.configure' === $route
            && 'commerceagents' === strtolower((string) $this->requestStack->getCurrentRequest()?->attributes->get('module_code'));
    }

    /**
     * Locale codes the module ships a translation catalog for, derived straight from
     * `I18n/*.php` (the files `BaseModule::initializeCoreI18n()` itself loads) so this
     * never drifts from what's actually installed.
     *
     * @return list<string>
     */
    private function coveredLocales(): array
    {
        $moduleDir = $this->module?->getModuleDir();

        if (null === $moduleDir) {
            return [];
        }

        $locales = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob($moduleDir.'/I18n/*.php') ?: [],
        );

        sort($locales);

        return $locales;
    }

    public function onMainFooterJs(HookRenderEvent $event): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [], ['commerceagents'], [AccessManager::VIEW])) {
            return;
        }

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/bo-chat-widget.html.twig', [
            'endpoint' => $this->urlGenerator->generate('commerceagents_merchant_chat'),
            'fullPageUrl' => $this->urlGenerator->generate('commerceagents_merchant_page'),
        ]));
    }

    public function onModuleConfigJs(HookRenderEvent $event): void
    {
        if (strtolower((string) $event->getArgument('modulecode')) !== 'commerceagents') {
            return;
        }

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/module-config-js.html.twig'));
    }

    public function onMainTopMenuItems(HookRenderEvent $event): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [], ['commerceagents'], [AccessManager::VIEW])) {
            return;
        }

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/menu-item.html.twig', [
            'agentsPageUrl' => $this->urlGenerator->generate('commerceagents_agents_page'),
            'isActive' => \in_array($event->getArgument('admin_current_location'), ['commerceagents_agents_page', 'commerceagents_agents_new', 'commerceagents_agents_edit'], true),
        ]));
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        if (strtolower((string) $event->getArgument('modulecode')) !== 'commerceagents') {
            return;
        }

        if (!$this->modelCatalog->hasBundledRows()) {
            // First display after install, or after a migration that ran before the Propel classes existed.
            $this->modelCatalog->seedFromBundledCatalog();
        }

        $catalog = [];
        $providers = [];
        foreach (LlmClientFactory::PROVIDERS as $provider) {
            $catalog[$provider] = array_map($this->modelToArray(...), $this->modelCatalog->listByProvider($provider));
            $providers[] = [
                'code' => $provider,
                'label' => self::PROVIDER_LABELS[$provider],
                'apiKeyConfigured' => $this->configService->hasApiKey($provider),
                'baseUrl' => $this->configService->getBaseUrl($provider),
                'model' => $this->configService->getModel($provider),
                'defaultBaseUrl' => self::DEFAULT_BASE_URLS[$provider],
                // Enabled models, plus the configured one even when disabled so its price still shows.
                'models' => array_values(array_filter(
                    $catalog[$provider],
                    fn (array $model): bool => $model['enabled'] || $model['modelId'] === $this->configService->getModel($provider),
                )),
            ];
        }

        $activeProvider = $this->configService->getProvider();
        $activeModel = $this->configService->getModel($activeProvider);
        $activePrices = $this->modelCatalog->pricesFor($activeProvider, $activeModel);
        $budget = $this->budgetGuard->status();
        $usage = $this->tokenUsageRepository->summarize();

        $mistralModel = $this->configService->getModel('mistral');
        $mistralModelChoices = array_map(self::modelChoiceToArray(...), $this->modelCatalog->getSelectableModels('mistral'));

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/module-configuration.html.twig', [
            'activeProvider' => $activeProvider,
            'providers' => $providers,
            'otherProviders' => array_filter($providers, static fn (array $provider): bool => $provider['code'] !== 'mistral'),
            'catalog' => $catalog,
            'hero' => [
                'apiKeyConfigured' => $this->configService->hasApiKey('mistral'),
                'baseUrl' => $this->configService->getBaseUrl('mistral'),
                'model' => $mistralModel,
                'modelChoices' => $mistralModelChoices,
                'modelPricedAt' => $this->modelCatalog->latestPricedAt('mistral')?->format('d/m/Y') ?? (new \DateTimeImmutable())->format('d/m/Y'),
            ],
            'dashboard' => [
                'providerLabel' => self::PROVIDER_LABELS[$activeProvider],
                'model' => $activeModel,
                'apiKeyConfigured' => $this->configService->hasApiKey($activeProvider),
                'modelPriced' => $activePrices['input'] !== null && $activePrices['output'] !== null,
                'modelPriceInput' => $activePrices['input'],
                'modelPriceOutput' => $activePrices['output'],
                'spentMonth' => self::formatUsd($budget->spent),
                'spentToday' => self::formatUsd($usage['today']['all']['cost']),
                'callsMonth' => $usage['this_month']['all']['calls'],
                'tokensMonth' => $usage['this_month']['all']['total'],
                'budget' => $budget->isLimited() ? self::formatUsd($budget->budget) : null,
                'remaining' => $budget->remaining() !== null ? self::formatUsd($budget->remaining()) : null,
                'percentUsed' => $budget->percentUsed(),
                'budgetState' => $budget->state(),
                'budgetBlocked' => $budget->isBlocked(),
                'monthLabel' => (new \DateTimeImmutable())->format('m/Y'),
            ],
            'alerts' => $this->buildAlerts($activeProvider, $activePrices, $budget),
            'budgetSettings' => [
                'monthlyBudgetUsd' => $budget->isLimited() ? self::trimDecimal(number_format($budget->budget, 4, '.', '')) : '',
                'warningPercent' => $budget->warningPercent,
                'block' => $budget->blockWhenExceeded,
            ],
            'assistantName' => $this->configService->getAssistantName(),
            'enableFrontChat' => $this->configService->isFrontChatEnabled(),
            'enableCart' => $this->configService->isCartEnabled(),
            'enableCheckout' => $this->configService->isCheckoutEnabled(),
            'enableOrders' => $this->configService->areOrdersEnabled(),
            'dailyMessageLimit' => $this->configService->getDailyMessageLimit(),
            'maxProactivePrompts' => $this->configService->getMaxProactivePrompts(),
            'lowStockThreshold' => $this->configService->getLowStockThreshold(),
            'policyContentIds' => implode(',', $this->configService->getPolicyContentIds()),
            'saveUrl' => $this->urlGenerator->generate('commerceagents_config_save'),
            'testUrl' => $this->urlGenerator->generate('commerceagents_config_test'),
            'refreshModelsUrl' => $this->urlGenerator->generate('commerceagents_models_refresh'),
            'saveModelsUrl' => $this->urlGenerator->generate('commerceagents_models_save'),
            'addModelUrl' => $this->urlGenerator->generate('commerceagents_models_add'),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            'tokenUsage' => $usage,
            'usageByModel' => $this->tokenUsageRepository->byModel((new \DateTimeImmutable())->setTime(0, 0)->modify('-29 days')),
            'agentTypes' => TokenUsageRepository::AGENT_TYPES,
            'channels' => $this->buildChannelsViewModel(),
        ]));
    }

    /**
     * "Canaux" tab (MYO-300): one card per registered connector, its form
     * fields generated from getSettingsSchema() — never hardcoded per
     * connector — with secret fields (format "uri") masked, exactly like the
     * LLM API key field above.
     *
     * @return list<array<string, mixed>>
     */
    private function buildChannelsViewModel(): array
    {
        $icons = ['mail' => 'bi-envelope', 'mattermost' => 'bi-chat-square-dots', 'slack' => 'bi-slack'];

        $channels = [];
        foreach ($this->channelRegistry->describeAll() as $description) {
            $code = $description['code'];
            $schema = $description['settingsSchema'];
            $current = $this->channelConfig->getSettings($code);
            $secretProperties = ChannelConnectorConfigService::secretProperties($schema);

            $fields = [];
            foreach ($schema['properties'] ?? [] as $name => $definition) {
                $isSecret = \in_array($name, $secretProperties, true);
                $fields[] = [
                    'name' => $name,
                    'label' => $definition['description'] ?? $name,
                    'type' => ($definition['format'] ?? null) === 'email' ? 'email' : ($isSecret ? 'password' : 'text'),
                    'required' => \in_array($name, $schema['required'] ?? [], true),
                    'secret' => $isSecret,
                    'value' => $isSecret ? '' : ($current[$name] ?? ''),
                    'configured' => $isSecret && ($current[$name] ?? '') !== '',
                ];
            }

            $channels[] = [
                'code' => $code,
                'label' => $description['label'],
                'icon' => $icons[$code] ?? 'bi-broadcast',
                'configured' => $current !== [],
                'fields' => $fields,
                'testUrl' => $this->urlGenerator->generate('commerceagents_channels_test', ['code' => $code]),
                'saveUrl' => $this->urlGenerator->generate('commerceagents_channels_save', ['code' => $code]),
            ];
        }

        return $channels;
    }

    /**
     * @param array{input: ?float, output: ?float} $activePrices
     *
     * @return list<array{level: string, icon: string, message: string, tab: string}>
     */
    private function buildAlerts(string $activeProvider, array $activePrices, BudgetStatus $budget): array
    {
        $alerts = [];

        if (!$this->configService->hasApiKey($activeProvider)) {
            $alerts[] = ['level' => 'danger', 'icon' => 'bi-key', 'message' => 'The active provider has no API key: both assistants are unavailable.', 'tab' => 'providers'];
        }
        if ($activePrices['input'] === null || $activePrices['output'] === null) {
            $alerts[] = ['level' => 'warning', 'icon' => 'bi-tag', 'message' => 'The active model has no price: its calls are counted in tokens but not in cost.', 'tab' => 'models'];
        }
        if ($budget->state() === BudgetStatus::EXCEEDED) {
            $alerts[] = [
                'level' => 'danger',
                'icon' => 'bi-exclamation-octagon',
                'message' => $budget->isBlocked() ? 'Monthly budget reached: the assistants are paused until next month or a higher budget.' : 'Monthly budget reached: the assistants keep running because blocking is off.',
                'tab' => 'budget',
            ];
        } elseif ($budget->state() === BudgetStatus::WARNING) {
            $alerts[] = ['level' => 'warning', 'icon' => 'bi-exclamation-triangle', 'message' => 'Monthly budget warning threshold reached.', 'tab' => 'budget'];
        }
        if (!$this->configService->isFrontChatEnabled()) {
            $alerts[] = ['level' => 'info', 'icon' => 'bi-chat-left', 'message' => 'The front shopping assistant is disabled.', 'tab' => 'assistants'];
        }

        return $alerts;
    }

    private static function formatUsd(float $amount): string
    {
        $decimals = $amount !== 0.0 && abs($amount) < 0.01 ? 4 : 2;

        return '$'.number_format($amount, $decimals, '.', ' ');
    }

    private function modelToArray(AgentModel $model): array
    {
        return [
            'id' => $model->getId(),
            'modelId' => $model->getModelId(),
            'name' => $model->getName() ?: $model->getModelId(),
            'priceInput' => self::trimDecimal($model->getPriceInput()),
            'priceOutput' => self::trimDecimal($model->getPriceOutput()),
            'contextWindow' => $model->getContextWindow(),
            'enabled' => (bool) $model->getEnabled(),
            'source' => $model->getSource(),
            'pricedAt' => $model->getPricedAt()?->format('Y-m-d'),
            'lastSeenAt' => $model->getLastSeenAt()?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array{modelId: string, name: string, tier: string, tierLabel: string, priceInput: string, priceOutput: string, currency: string, contextWindow: ?int, isDefault: bool}
     */
    private static function modelChoiceToArray(ModelChoice $choice): array
    {
        return [
            'modelId' => $choice->modelId,
            'name' => $choice->name,
            'tier' => $choice->tier,
            'tierLabel' => $choice->tierLabel,
            'priceInput' => $choice->priceInput,
            'priceOutput' => $choice->priceOutput,
            'currency' => $choice->currency,
            'contextWindow' => $choice->contextWindow,
            'isDefault' => $choice->isDefault,
        ];
    }

    private static function trimDecimal(?string $decimal): ?string
    {
        if ($decimal === null) {
            return null;
        }
        $trimmed = rtrim(rtrim($decimal, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
