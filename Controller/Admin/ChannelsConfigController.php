<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Hook\Admin\AdminHookManager;
use CommerceAgents\Service\Channel\ChannelConnectorConfigService;
use CommerceAgents\Service\Channel\ChannelSettingsValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;

/**
 * "Canaux" tab of the module configuration screen (MYO-300): one endpoint to
 * probe a connector's settings without saving them, one to persist them
 * (encrypted, via ChannelConnectorConfigService). Both are meant to be
 * called through fetch() from the "Canaux" tab, mirroring
 * ConfigSaveController::testConnection() — no page reload either way.
 */
final readonly class ChannelsConfigController
{
    public function __construct(
        private AdminAccessChecker $access,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ChannelConnectorRegistry $registry,
        private ChannelConnectorConfigService $channelConfig,
        private ChannelSettingsValidator $validator,
    ) {
    }

    #[Route('/admin/module/commerceagents/channels/{code}/test', name: 'commerceagents_channels_test', methods: ['POST'])]
    public function test(string $code, Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $connector = $this->registry->get($code);
        if ($connector === null) {
            return new JsonResponse(['success' => false, 'message' => \sprintf('Connecteur "%s" inconnu.', $code)], Response::HTTP_NOT_FOUND);
        }

        $schema = $connector->getSettingsSchema();
        $effective = $this->channelConfig->merge($code, (array) $request->request->all('settings'), $schema);

        $errors = $this->validator->validate($schema, $effective);
        if ($errors !== []) {
            return new JsonResponse(['success' => false, 'message' => implode(' ', $errors)]);
        }

        $result = $connector->test($effective);

        return new JsonResponse(['success' => $result->success, 'message' => $result->message]);
    }

    #[Route('/admin/module/commerceagents/channels/{code}/save', name: 'commerceagents_channels_save', methods: ['POST'])]
    public function save(string $code, Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $connector = $this->registry->get($code);
        if ($connector === null) {
            return new JsonResponse(['success' => false, 'message' => \sprintf('Connecteur "%s" inconnu.', $code)], Response::HTTP_NOT_FOUND);
        }

        $schema = $connector->getSettingsSchema();
        $effective = $this->channelConfig->merge($code, (array) $request->request->all('settings'), $schema);

        $errors = $this->validator->validate($schema, $effective);
        if ($errors !== []) {
            return new JsonResponse(['success' => false, 'message' => implode(' ', $errors)]);
        }

        $this->channelConfig->persist($code, $effective);

        return new JsonResponse(['success' => true, 'message' => 'Réglages enregistrés.', 'configured' => true]);
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
}
