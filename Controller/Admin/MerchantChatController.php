<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\AgentDefinitionManager;
use CommerceAgents\Service\AgentDefinitionSeeder;
use CommerceAgents\Service\AgentMemoryManager;
use CommerceAgents\Service\BudgetGuard;
use CommerceAgents\Service\ChatStreamer;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\Locale\AssistantLocaleResolver;
use CommerceAgents\Service\SystemPromptFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Twig\Environment;

final readonly class MerchantChatController
{
    private const MAX_MESSAGE_LENGTH = 2000;

    /**
     * Dedicated token (MYO-284 M4): chat() posts a JSON body from fetch(),
     * not a classic form submit, so the token travels as a header instead of
     * a hidden `_token` field -- the same mechanism the other admin
     * controllers of this module use, adapted to this transport.
     */
    public const CSRF_TOKEN_ID = 'commerceagents_merchant_chat';
    public const CSRF_HEADER = 'X-CSRF-Token';

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
        private AgentDefinitionManager $agentDefinitionManager,
        private AgentMemoryManager $agentMemoryManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
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
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    #[Route('/admin/merchant-agent/chat', name: 'commerceagents_merchant_chat', methods: ['POST'])]
    public function chat(Request $request): Response
    {
        return $this->handleChat($request, null);
    }

    /**
     * Per-agent conversation (MYO-325): same mechanics as the global merchant
     * chat above, scoped to one agent_definition's own role prompt, memory
     * and capability-restricted tools instead of the unrestricted merchant
     * assistant.
     */
    #[Route('/admin/module/CommerceAgents/agents/{id}/chat', name: 'commerceagents_agents_chat', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function agentChat(int $id, Request $request): Response
    {
        return $this->handleChat($request, $id);
    }

    private function handleChat(Request $request, ?int $agentId): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get(self::CSRF_HEADER));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        $userMessage = trim((string) ($payload['message'] ?? ''));

        if ($userMessage === '') {
            return new JsonResponse(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($userMessage) > self::MAX_MESSAGE_LENGTH) {
            return new JsonResponse(['error' => \sprintf('Message exceeds %d characters', self::MAX_MESSAGE_LENGTH)], Response::HTTP_BAD_REQUEST);
        }

        $assistant = $agentId !== null
            ? $this->agentDefinitionManager->find($agentId)
            : $this->agentDefinitionManager->findByCode(AgentDefinitionSeeder::MERCHANT_CODE);
        if ($agentId !== null && $assistant === null) {
            return new JsonResponse(['error' => 'Agent not found'], Response::HTTP_NOT_FOUND);
        }

        $llmConfig = $agentId !== null
            ? $this->configService->getLlmConfigForAgent($assistant->getProvider(), $assistant->getModel())
            : $this->configService->getLlmConfig();
        if ($llmConfig->apiKey === '') {
            return new JsonResponse(['error' => 'LLM provider is not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($this->budgetGuard->status()->isBlocked()) {
            return new JsonResponse(['error' => 'Monthly LLM budget reached'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $admin = $this->securityContext->getAdminUser();
        $locale = $this->localeResolver->forAdmin($admin->getLocale());

        $conversation = $this->conversationService->getOrCreate(
            $agentId !== null ? 'agent_chat' : 'merchant',
            $agentId !== null ? \sprintf('%d:%s', $agentId, $request->getSession()->getId()) : $request->getSession()->getId(),
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
            agentDefinitionId: $assistant?->getId(),
            capabilities: $agentId !== null ? $this->capabilityCodes($assistant) : null,
        );
        $this->conversationService->appendMessage($conversation->getId(), 'user', $userMessage);
        $history = $this->conversationService->getHistory($conversation->getId());

        $runtime = new AgentRuntime($this->llmClientFactory->create($llmConfig->provider), $this->toolRegistry);

        $system = $this->systemPrompt($assistant, $locale);

        return $this->chatStreamer->stream($runtime, $history, $system, $toolContext, $llmConfig, $conversation);
    }

    /**
     * Mirrors AgentsController::promptPreviewData()'s per-code dispatch, so
     * an agent's chat prompt is always the exact same prompt its autonomous
     * runs and its "effective prompt" preview use.
     */
    private function systemPrompt(?AgentDefinition $assistant, string $locale): string
    {
        $rolePrompt = $assistant?->getRolePrompt();
        $memoryEntries = $assistant !== null ? $this->agentMemoryManager->activeContents($assistant->getId()) : [];

        return match ($assistant?->getCode()) {
            AgentDefinitionSeeder::SHOPPING_CODE => $this->systemPromptFactory->shopping($this->configService->getAssistantName(), $locale, $rolePrompt, $memoryEntries),
            AgentDefinitionSeeder::MERCHANT_CODE, null => $this->systemPromptFactory->merchant($locale, $rolePrompt, $memoryEntries),
            default => $this->systemPromptFactory->agent((string) $assistant->getTitle(), (string) $rolePrompt, $locale, $memoryEntries),
        };
    }

    /**
     * @return list<string>
     */
    private function capabilityCodes(AgentDefinition $definition): array
    {
        $codes = [];
        foreach ($definition->getAgentCapabilities() as $capability) {
            $codes[] = $capability->getCapability();
        }

        return $codes;
    }
}
