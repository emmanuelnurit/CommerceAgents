<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Hook\Admin\AdminHookManager;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\ConnectionTester;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\Security\OutboundUrlValidatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;

final readonly class ConfigSaveController
{
    private const TOGGLES = ['enable_front_chat', 'enable_cart', 'enable_checkout', 'enable_orders'];

    public function __construct(
        private AdminAccessChecker $access,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private AgentConfigService $configService,
        private ConnectionTester $connectionTester,
        private ModelCatalog $modelCatalog,
        private OutboundUrlValidatorInterface $urlValidator,
    ) {
    }

    #[Route('/admin/module/commerceagents/test-connection', name: 'commerceagents_config_test', methods: ['POST'])]
    public function testConnection(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $provider = $this->requestedProvider($request);

        return new JsonResponse($this->connectionTester->test($this->configService->getLlmConfig($provider)));
    }

    #[Route('/admin/module/commerceagents/models/refresh', name: 'commerceagents_models_refresh', methods: ['POST'])]
    public function refreshModels(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $provider = $this->requestedProvider($request);

        try {
            $result = $this->modelCatalog->refreshFromProvider($this->configService->getLlmConfig($provider));
        } catch (\Throwable $exception) {
            return new JsonResponse(['success' => false, 'message' => $exception->getMessage()]);
        }

        return new JsonResponse([
            'success' => true,
            'message' => \sprintf('%d model(s) available, %d new', $result['seen'], $result['added']),
            'added' => $result['added'],
            'seen' => $result['seen'],
        ]);
    }

    #[Route('/admin/module/commerceagents/models/save', name: 'commerceagents_models_save', methods: ['POST'])]
    public function saveModels(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $edits = $request->request->all('models');
        $this->modelCatalog->applyEdits(\is_array($edits) ? $edits : []);

        return $this->redirectToConfiguration('models');
    }

    #[Route('/admin/module/commerceagents/models/add', name: 'commerceagents_models_add', methods: ['POST'])]
    public function addModel(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $provider = $this->requestedProvider($request);
        $modelId = trim((string) $request->request->get('model_id'));

        if ($modelId !== '' && preg_match('/^[A-Za-z0-9._\/:-]+$/', $modelId) === 1) {
            $this->modelCatalog->addManual(
                $provider,
                $modelId,
                (string) $request->request->get('name'),
                (string) $request->request->get('price_input'),
                (string) $request->request->get('price_output'),
            );
        }

        return $this->redirectToConfiguration('models');
    }

    #[Route('/admin/module/commerceagents/save', name: 'commerceagents_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $this->configService->setProvider((string) $request->request->get('provider', LlmClientFactory::DEFAULT_PROVIDER));

        foreach (LlmClientFactory::PROVIDERS as $provider) {
            $baseUrl = trim((string) $request->request->get(AgentConfigService::providerKey('base_url', $provider)));
            // MYO-276: base_url is dialed with the provider API key attached
            // and its response echoed back to the BO (test-connection, model
            // refresh) -- an unvalidated value is a live SSRF against the
            // internal network. Reject anything that isn't https to a public
            // address; the LLM clients already fall back to the official
            // provider endpoint when base_url is empty.
            if ($baseUrl !== '' && !$this->urlValidator->isAllowed($baseUrl)) {
                $baseUrl = '';
            }

            $this->configService->setProviderSettings(
                $provider,
                trim((string) $request->request->get(AgentConfigService::providerKey('api_key', $provider))),
                $baseUrl,
                (string) $request->request->get(AgentConfigService::providerKey('model', $provider)),
            );
        }

        CommerceAgents::setConfigValue('assistant_name', trim((string) $request->request->get('assistant_name')) ?: 'Alex');
        CommerceAgents::setConfigValue('daily_message_limit', (string) max(1, (int) $request->request->get('daily_message_limit', 200)));
        $this->configService->setMaxProactivePrompts((int) $request->request->get('max_proactive_prompts', 3));
        $this->configService->setLowStockThreshold((int) $request->request->get('low_stock_threshold', 5));
        CommerceAgents::setConfigValue('policy_content_ids', trim((string) $request->request->get('policy_content_ids')));

        foreach (self::TOGGLES as $toggle) {
            CommerceAgents::setConfigValue($toggle, $request->request->get($toggle) === '1' ? '1' : '0');
        }

        $this->configService->setBudget(
            (float) str_replace(',', '.', (string) $request->request->get('monthly_budget_usd', '0')),
            (int) $request->request->get('budget_warning_percent', 80),
            $request->request->get('budget_block') === '1',
        );

        return $this->redirectToConfiguration((string) $request->request->get('_tab', 'providers'));
    }

    private function guard(Request $request): ?Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::UPDATE)) {
            return $denied;
        }

        $token = new CsrfToken(AdminHookManager::CSRF_TOKEN_ID, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function requestedProvider(Request $request): string
    {
        $provider = (string) $request->request->get('provider', '');

        return \in_array($provider, LlmClientFactory::PROVIDERS, true) ? $provider : $this->configService->getProvider();
    }

    private const TABS = ['providers', 'models', 'assistants', 'budget', 'usage'];

    private function redirectToConfiguration(?string $tab = null): RedirectResponse
    {
        $url = $this->urlGenerator->generate('admin.module.configure', ['module_code' => 'CommerceAgents']);

        return new RedirectResponse(\in_array($tab, self::TABS, true) ? $url.'#'.$tab : $url);
    }
}
