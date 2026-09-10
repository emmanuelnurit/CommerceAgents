<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Hook\Admin\AdminHookManager;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\ConnectionTester;
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
    ) {
    }

    #[Route('/admin/module/commerceagents/test-connection', name: 'commerceagents_config_test', methods: ['POST'])]
    public function testConnection(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::UPDATE)) {
            return $denied;
        }

        $token = new CsrfToken(AdminHookManager::CSRF_TOKEN_ID, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->connectionTester->test($this->configService->getLlmConfig()));
    }

    #[Route('/admin/module/commerceagents/save', name: 'commerceagents_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::UPDATE)) {
            return $denied;
        }

        $token = new CsrfToken(AdminHookManager::CSRF_TOKEN_ID, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        $provider = (string) $request->request->get('provider', 'anthropic');
        CommerceAgents::setConfigValue('provider', \in_array($provider, LlmClientFactory::PROVIDERS, true) ? $provider : 'anthropic');
        CommerceAgents::setConfigValue('model', trim((string) $request->request->get('model')));
        CommerceAgents::setConfigValue('base_url', trim((string) $request->request->get('base_url')));
        CommerceAgents::setConfigValue('assistant_name', trim((string) $request->request->get('assistant_name')) ?: 'Alex');
        CommerceAgents::setConfigValue('daily_message_limit', (string) max(1, (int) $request->request->get('daily_message_limit', 200)));
        CommerceAgents::setConfigValue('policy_content_ids', trim((string) $request->request->get('policy_content_ids')));

        foreach (self::TOGGLES as $toggle) {
            CommerceAgents::setConfigValue($toggle, $request->request->get($toggle) === '1' ? '1' : '0');
        }

        $apiKey = trim((string) $request->request->get('api_key'));
        if ($apiKey !== '') {
            CommerceAgents::setConfigValue('api_key', $apiKey);
        }

        return new RedirectResponse(
            $this->urlGenerator->generate('admin.module.configure', ['module_code' => 'CommerceAgents']),
        );
    }
}
