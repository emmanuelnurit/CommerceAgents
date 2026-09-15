<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Front;

use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Proactive\ProactiveMessage;
use CommerceAgents\Agent\Proactive\ProactiveSignal;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolRegistry;
use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Service\BudgetGuard;
use CommerceAgents\Service\ChatStreamer;
use CommerceAgents\Service\ConversationService;
use CommerceAgents\Service\LanguageReminder;
use CommerceAgents\Service\ProactiveGuard;
use CommerceAgents\Service\ProactiveScenarioRegistry;
use CommerceAgents\Service\ProactiveSessionRepository;
use CommerceAgents\Service\SystemPromptFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Condition\Exception\UnmatchableConditionException;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Coupon\CouponConsumeEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Domain\Cart\CartFacade;
use Thelia\Domain\Promotion\Coupon\Exception\CouponExpiredException;
use Thelia\Domain\Promotion\Coupon\Exception\CouponNotReleaseException;
use Thelia\Domain\Promotion\Coupon\Exception\CouponNoUsageLeftException;
use Thelia\Domain\Promotion\Coupon\Exception\InactiveCouponException;

final class ChatController extends BaseFrontController
{
    private const MAX_MESSAGE_LENGTH = 2000;
    private const MAX_SIGNAL_TYPE_LENGTH = 60;

    public function __construct(
        private readonly AgentConfigService $configService,
        private readonly BudgetGuard $budgetGuard,
        private readonly ConversationService $conversationService,
        private readonly LlmClientFactory $llmClientFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly ChatStreamer $chatStreamer,
        private readonly SystemPromptFactory $systemPromptFactory,
        private readonly CartFacade $cartFacade,
        private readonly ProactiveGuard $proactiveGuard,
        private readonly ProactiveSessionRepository $proactiveSessionRepository,
        private readonly ProactiveScenarioRegistry $proactiveScenarioRegistry,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
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

    /**
     * Distinct from chat(): a lightweight endpoint the widget polls on
     * client-side signals (cart idle, low stock viewed, ...). The
     * ProactiveGuard gate (dismissed > frequency > repetition > budget) runs
     * before any data resolution or LLM call, so it never returns a message
     * once the visitor has refused/closed the widget for this session.
     */
    #[Route('/agent/chat/proactive-check', name: 'commerceagents_chat_proactive_check', methods: ['POST'])]
    public function proactiveCheck(Request $request): Response
    {
        if (!$this->configService->isFrontChatEnabled()) {
            throw new NotFoundHttpException();
        }

        $payload = json_decode((string) $request->getContent(), true);
        $signalType = trim((string) ($payload['signal_type'] ?? ''));
        $signalContext = \is_array($payload['context'] ?? null) ? $payload['context'] : [];

        if ($signalType === '' || mb_strlen($signalType) > self::MAX_SIGNAL_TYPE_LENGTH) {
            return new JsonResponse(['error' => 'signal_type is required'], Response::HTTP_BAD_REQUEST);
        }

        $session = $request->getSession();
        $customerId = $session->getCustomerUser()?->getId();
        $locale = $session->getLang()->getLocale();

        $conversation = $this->conversationService->getOrCreate('shopping', $session->getId(), $customerId, $locale);
        $now = new \DateTimeImmutable();
        $verdict = $this->proactiveGuard->evaluate(
            $this->proactiveSessionRepository->stateOf($conversation),
            $signalType,
            $now,
            $this->configService->getMaxProactivePrompts(),
            $this->budgetGuard->status($now)->isBlocked(),
        );

        if (!$verdict->eligible) {
            $this->logger->info('[commerce-agents] proactive check blocked', [
                'conversation_id' => $conversation->getId(),
                'signal_type' => $signalType,
                'reason' => $verdict->reason,
            ]);

            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        $toolContext = new ToolContext(
            isAdmin: false,
            customerId: $customerId,
            conversationId: $conversation->getId(),
            sessionId: $session->getId(),
            locale: $locale,
            currencyCode: $session->getCurrency()->getCode(),
        );

        $message = $this->proactiveScenarioRegistry->resolve(new ProactiveSignal($signalType, $signalContext), $toolContext);

        if ($message === null) {
            $this->logger->info('[commerce-agents] proactive check passed the gate, no scenario resolved', [
                'conversation_id' => $conversation->getId(),
                'signal_type' => $signalType,
            ]);

            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        $this->proactiveSessionRepository->recordPrompt($conversation, $signalType, $now);

        $this->logger->info('[commerce-agents] proactive message sent', [
            'conversation_id' => $conversation->getId(),
            'signal_type' => $signalType,
        ]);

        return new JsonResponse([
            'scenario' => $signalType,
            'text' => $message->message,
            'card' => self::productCard($message) ?? self::couponCard($message),
        ]);
    }

    /**
     * The "Appliquer" button on the coupon card (MYO-245 UX spec, component
     * 3) never applies the code itself — it calls this endpoint, which
     * dispatches the same TheliaEvents::COUPON_CONSUME event the native
     * cart coupon form uses (Thelia\Action\Coupon::consume,
     * CouponManager::pushCouponInSession under the hood). The LLM only ever
     * suggested a real code (SuggestApplicableCouponsTool); this endpoint
     * re-validates it server-side before touching the session.
     */
    #[Route('/agent/chat/proactive-apply-coupon', name: 'commerceagents_chat_proactive_apply_coupon', methods: ['POST'])]
    public function proactiveApplyCoupon(Request $request): Response
    {
        if (!$this->configService->isFrontChatEnabled()) {
            throw new NotFoundHttpException();
        }

        $payload = json_decode((string) $request->getContent(), true);
        $code = trim((string) ($payload['code'] ?? ''));

        if ($code === '') {
            return new JsonResponse(['error' => 'code is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = new CouponConsumeEvent($code);
            $this->eventDispatcher->dispatch($event, TheliaEvents::COUPON_CONSUME);
        } catch (CouponExpiredException|CouponNotReleaseException|CouponNoUsageLeftException|InactiveCouponException|UnmatchableConditionException $exception) {
            $this->logger->info('[commerce-agents] proactive coupon apply rejected', [
                'code' => $code,
                'reason' => $exception->getMessage(),
            ]);

            return new JsonResponse(['applied' => false, 'error' => 'Ce code n\'est plus valide'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$event->getIsValid()) {
            return new JsonResponse(['applied' => false, 'error' => 'Ce code ne s\'applique pas à votre panier'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->logger->info('[commerce-agents] proactive coupon applied', ['code' => $code]);

        return new JsonResponse(['applied' => true, 'discount' => $event->getDiscount()]);
    }

    /**
     * @return array{type: string, data: array<string, mixed>}|null
     */
    private static function couponCard(ProactiveMessage $message): ?array
    {
        if ($message->couponCode === null && $message->tiers === null) {
            return null;
        }

        $data = array_filter([
            'code' => $message->couponCode,
            'conditionLabel' => $message->conditionLabel,
            'progressLabel' => $message->progressLabel,
            'progressPercent' => $message->progressPercent,
            'progressAria' => $message->progressAria,
            'tiers' => $message->tiers,
        ], static fn (mixed $value): bool => $value !== null);

        return ['type' => 'coupon', 'data' => $data];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}|null
     */
    private static function productCard(ProactiveMessage $message): ?array
    {
        if ($message->productId === null) {
            return null;
        }

        $data = array_filter([
            'id' => $message->productId,
            'title' => $message->productTitle,
            'url' => $message->productUrl,
            'imageUrl' => $message->productImageUrl,
            'price' => $message->productPrice,
            'promoPrice' => $message->productPromoPrice,
            'currency' => $message->productCurrency,
            'inStock' => $message->productInStock,
            'stockLabel' => $message->productStockLabel,
        ], static fn (mixed $value): bool => $value !== null);

        return ['type' => 'product', 'data' => $data];
    }

    /**
     * The widget calls this as soon as the visitor closes or refuses a
     * proactive message, so the flag persists on the session and every
     * later proactive-check for it short-circuits on ProactiveGuard's first
     * gate, with no exception.
     */
    #[Route('/agent/chat/proactive-dismiss', name: 'commerceagents_chat_proactive_dismiss', methods: ['POST'])]
    public function proactiveDismiss(Request $request): Response
    {
        $session = $request->getSession();
        $customerId = $session->getCustomerUser()?->getId();
        $locale = $session->getLang()->getLocale();

        $conversation = $this->conversationService->getOrCreate('shopping', $session->getId(), $customerId, $locale);
        $this->proactiveSessionRepository->recordDismissal($conversation);

        $this->logger->info('[commerce-agents] proactive widget dismissed', [
            'conversation_id' => $conversation->getId(),
        ]);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
