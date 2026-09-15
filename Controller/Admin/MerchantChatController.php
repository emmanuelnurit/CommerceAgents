<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\BudgetGuard;
use CommerceAgents\Service\ChatStreamer;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\SystemPromptFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Twig\Environment;

final readonly class MerchantChatController
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private AgentConfigService $configService,
        private BudgetGuard $budgetGuard,
        private ConversationService $conversationService,
        private LlmClientFactory $llmClientFactory,
        private ToolRegistry $toolRegistry,
        private ChatStreamer $chatStreamer,
        private SystemPromptFactory $systemPromptFactory,
        private Environment $twig,
        private AssistantLocaleResolver $localeResolver,
    ) {
    }

    #[Route('/admin/merchant-agent', name: 'commerceagents_merchant_page', methods: ['GET'])]
    public function page(): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/merchant-chat/page.html.twig', [
            'assistantName' => $this->configService->getAssistantName(),
            'apiKeyConfigured' => $this->configService->getLlmConfig()->apiKey !== '',
        ]));
    }

    #[Route('/admin/merchant-agent/chat', name: 'commerceagents_merchant_chat', methods: ['POST'])]
    public function chat(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $payload = json_decode((string) $request->getContent(), true);
        $userMessage = trim((string) ($payload['message'] ?? ''));

        if ($userMessage === '') {
            return new JsonResponse(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($userMessage) > self::MAX_MESSAGE_LENGTH) {
            return new JsonResponse(['error' => \sprintf('Message exceeds %d characters', self::MAX_MESSAGE_LENGTH)], Response::HTTP_BAD_REQUEST);
        }

        $llmConfig = $this->configService->getLlmConfig();
        if ($llmConfig->apiKey === '') {
            return new JsonResponse(['error' => 'LLM provider is not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($this->budgetGuard->status()->isBlocked()) {
            return new JsonResponse(['error' => 'Monthly LLM budget reached'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $admin = $this->securityContext->getAdminUser();
        $locale = $this->localeResolver->forAdmin($admin->getLocale());

        $conversation = $this->conversationService->getOrCreate(
            'merchant',
            $request->getSession()->getId(),
            null,
            $locale,
            $admin->getId(),
        );

        $toolContext = new ToolContext(
            isAdmin: true,
            adminId: $admin->getId(),
            conversationId: $conversation->getId(),
            sessionId: $request->getSession()->getId(),
            locale: $locale,
        );
        $this->conversationService->appendMessage($conversation->getId(), 'user', $userMessage);
        $history = $this->conversationService->getHistory($conversation->getId());

        $runtime = new AgentRuntime($this->llmClientFactory->create($llmConfig->provider), $this->toolRegistry);

        return $this->chatStreamer->stream($runtime, $history, $this->systemPromptFactory->merchant($locale), $toolContext, $llmConfig, $conversation);
    }
}
