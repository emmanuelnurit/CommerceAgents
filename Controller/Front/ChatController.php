<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Front;

use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\ChatStreamer;
use CommerceAgents\Service\ConversationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Request;

final class ChatController extends BaseFrontController
{
    private const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        private readonly AgentConfigService $configService,
        private readonly ConversationService $conversationService,
        private readonly LlmClientFactory $llmClientFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly ChatStreamer $chatStreamer,
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
        $history = $this->conversationService->getHistory($conversation->getId());

        $runtime = new AgentRuntime($this->llmClientFactory->create($llmConfig->provider), $this->toolRegistry);

        return $this->chatStreamer->stream($runtime, $history, $this->buildSystemPrompt($locale), $toolContext, $llmConfig, $conversation);
    }

    private function buildSystemPrompt(string $locale): string
    {
        return sprintf(
            'You are %s, the shopping assistant of this online store. '
            .'Only discuss topics related to this store and its products. '
            .'Never invent prices or discounts, never ask for payment card details. '
            .'Answer in the language of this locale: %s.',
            $this->configService->getAssistantName(),
            $locale,
        );
    }
}
