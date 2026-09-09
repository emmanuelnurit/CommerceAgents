<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Front;

use CommerceAgents\Agent\AgentEvent;
use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\ConversationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
        $system = $this->buildSystemPrompt($locale);

        $response = new StreamedResponse(function () use ($runtime, $history, $system, $toolContext, $llmConfig, $conversation): void {
            $assistantText = '';

            foreach ($runtime->runTurn($history, $system, $toolContext, $llmConfig) as $event) {
                if ($event->type === AgentEvent::TEXT_DELTA) {
                    $assistantText .= $event->payload['text'];
                } elseif ($event->type === AgentEvent::TOOL_CALL) {
                    $this->conversationService->appendMessage(
                        $conversation->getId(),
                        'assistant',
                        $assistantText,
                        [['id' => $event->payload['id'], 'name' => $event->payload['name'], 'arguments' => $event->payload['arguments']]],
                    );
                    $assistantText = '';
                } elseif ($event->type === AgentEvent::TOOL_RESULT) {
                    $this->conversationService->appendMessage(
                        $conversation->getId(),
                        'tool',
                        json_encode($event->payload['result'], \JSON_THROW_ON_ERROR),
                        ['tool_call_id' => $event->payload['id']],
                    );
                }

                echo 'event: '.$event->type."\n";
                echo 'data: '.json_encode($event->payload, \JSON_THROW_ON_ERROR)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            if ($assistantText !== '') {
                $this->conversationService->appendMessage($conversation->getId(), 'assistant', $assistantText);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
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
