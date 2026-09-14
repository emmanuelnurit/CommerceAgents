<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Front;

use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\BudgetGuard;
use CommerceAgents\Service\ChatStreamer;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\LanguageReminder;
use CommerceAgents\Service\SystemPromptFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Domain\Cart\CartFacade;

final class ChatController extends BaseFrontController
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private readonly AgentConfigService $configService,
        private readonly BudgetGuard $budgetGuard,
        private readonly ConversationService $conversationService,
        private readonly LlmClientFactory $llmClientFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly ChatStreamer $chatStreamer,
        private readonly SystemPromptFactory $systemPromptFactory,
        private readonly CartFacade $cartFacade,
    ) {
    }

    #[Route('/agent/chat', name: 'commerceagents_chat', methods: ['POST'])]
    public function chat(Request $request): Response
    {
        if (!$this->configService->isFrontChatEnabled()) {
            throw new NotFoundHttpException();
        }

        $payload = json_decode((string) $request->getContent(), true);
        $userMessage = trim((string) ($payload['message'] ?? ''));

        if ($userMessage === '') {
            return new JsonResponse(['error' => 'Message is required'], Response::HTTP_BAD_REQUEST);
        }
        if (mb_strlen($userMessage) > self::MAX_MESSAGE_LENGTH) {
            return new JsonResponse(['error' => sprintf('Message exceeds %d characters', self::MAX_MESSAGE_LENGTH)], Response::HTTP_BAD_REQUEST);
        }

        $llmConfig = $this->configService->getLlmConfig();
        if ($llmConfig->apiKey === '') {
            return new JsonResponse(['error' => 'LLM provider is not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ($this->budgetGuard->status()->isBlocked()) {
            return new JsonResponse(['error' => 'Monthly LLM budget reached'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $session = $request->getSession();
        $customerId = $session->getCustomerUser()?->getId();
        $locale = $session->getLang()->getLocale();

        $toolContext = new ToolContext(
            isAdmin: false,
            customerId: $customerId,
            sessionId: $session->getId(),
            locale: $locale,
            currencyCode: $session->getCurrency()->getCode(),
        );

        $conversation = $this->conversationService->getOrCreate('shopping', $session->getId(), $customerId, $locale);
        $this->conversationService->appendMessage($conversation->getId(), 'user', $userMessage);
        // The system prompt alone loses against the language the visitor writes
        // in; the directive has to ride on the last user turn.
        $history = LanguageReminder::apply(
            $this->conversationService->getHistory($conversation->getId()),
            $locale,
        );

        // Warm up the session cart while the real session is still writable:
        // once the stream starts the session is frozen (see SessionFreezer) and
        // a cart created mid-stream would never be attached to the visitor.
        $this->cartFacade->getOrCreateFromSession();

        $runtime = new AgentRuntime($this->llmClientFactory->create($llmConfig->provider), $this->toolRegistry);

        $system = $this->systemPromptFactory->shopping($this->configService->getAssistantName(), $locale);

        return $this->chatStreamer->stream($runtime, $history, $system, $toolContext, $llmConfig, $conversation);
    }
}
