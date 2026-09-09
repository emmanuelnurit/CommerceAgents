<?php

declare(strict_types=1);

namespace CommerceAgents\Hook\Admin;

use CommerceAgents\Service\AgentConfigService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\ParserResolver;
use Twig\Environment;

class AdminHookManager extends BaseHook
{
    public const CSRF_TOKEN_ID = 'commerceagents_config';

    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly AgentConfigService $configService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
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
        ];
    }

    public function onMainTopMenuItems(HookRenderEvent $event): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [], ['commerceagents'], [AccessManager::VIEW])) {
            return;
        }

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/menu-item.html.twig', [
            'merchantPageUrl' => $this->urlGenerator->generate('commerceagents_merchant_page'),
            'isActive' => $event->getArgument('admin_current_location') === 'commerceagents_merchant_page',
        ]));
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        if (strtolower((string) $event->getArgument('modulecode')) !== 'commerceagents') {
            return;
        }

        $llmConfig = $this->configService->getLlmConfig();

        $event->add($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/hook/module-configuration.html.twig', [
            'provider' => $this->configService->getProvider(),
            'model' => $llmConfig->model,
            'baseUrl' => $llmConfig->baseUrl,
            'apiKeyConfigured' => $llmConfig->apiKey !== '',
            'assistantName' => $this->configService->getAssistantName(),
            'enableFrontChat' => $this->configService->isFrontChatEnabled(),
            'enableCart' => $this->configService->isCartEnabled(),
            'enableCheckout' => $this->configService->isCheckoutEnabled(),
            'enableOrders' => $this->configService->areOrdersEnabled(),
            'dailyMessageLimit' => $this->configService->getDailyMessageLimit(),
            'policyContentIds' => implode(',', $this->configService->getPolicyContentIds()),
            'saveUrl' => $this->urlGenerator->generate('commerceagents_config_save'),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }
}
