<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Model\AgentActionLogQuery;
use CommerceAgents\Model\AgentRunQuery;
use CommerceAgents\Service\BudgetGuard;
use CommerceAgents\Service\Merchant\TheliaStagedChangeRepository;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\ScopeCatalog;
use CommerceAgents\Service\SkillCatalog;
use CommerceAgents\StagedChange\BriefUrgencyClassifier;
use CommerceAgents\StagedChange\StagedChangeData;
use CommerceAgents\StagedChange\StagedChangeManager;
use CommerceAgents\Tool\Admin\Gateway\ReviewsGatewayInterface;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Model\CategoryQuery;
use Twig\Environment;

/**
 * "Le Brief" (MYO-472, concept MYO-469): the morning decision screen, an
 * evolution of the former "Proposed changes" console on the same route --
 * every existing link into `commerceagents_changes` keeps working unchanged.
 */
final readonly class StagedChangesController
{
    public const CSRF_TOKEN_ID = 'commerceagents_changes';

    /**
     * Documented estimate, not a measured value (MYO-472 AC5 "~X min
     * économisées"): average time a merchant would spend researching and
     * drafting the equivalent action by hand. Multiplied by the real count
     * of changes applied this month.
     */
    private const ESTIMATED_MINUTES_PER_CHANGE = 4;

    /**
     * Read-only recap group ("Pour information"): a short, recent list is
     * enough, it is not a decision queue (see repository).
     */
    private const RECENTLY_RESOLVED_LIMIT = 10;

    /**
     * Approving a staged change must also be gated by the native ACL resource
     * it actually mutates, not just the 'commerceagents' module right --
     * otherwise a BO user with module access but no catalog rights could
     * write prices/stock by proposing then approving their own change.
     *
     * @var array<string, string>
     */
    private const NATIVE_RESOURCE_BY_TARGET_TYPE = [
        'pse_price' => AdminResources::PRODUCT,
        'pse_stock' => AdminResources::PRODUCT,
    ];

    /** Sentinel: a field was submitted but failed validation (distinct from "no edit submitted" = null). */
    private const EDIT_INVALID = 'invalid';

    public function __construct(
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private TheliaStagedChangeRepository $repository,
        private StagedChangeManager $manager,
        private ReviewsGatewayInterface $reviewsGateway,
        private BudgetGuard $budgetGuard,
        private SkillCatalog $skillCatalog,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
        private Translator $translator,
    ) {
    }

    #[Route('/admin/merchant-agent/changes', name: 'commerceagents_changes', methods: ['GET'])]
    public function list(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $agentId = $request->query->get('agentId');
        $agentDefinitionId = \is_numeric($agentId) ? (int) $agentId : null;
        $canApprove = $this->securityContext->isGranted(['ADMIN'], [], ['commerceagents'], [AccessManager::UPDATE]);

        $pending = $this->repository->findPending($agentDefinitionId);
        $resolved = $this->repository->findRecentlyResolved(self::RECENTLY_RESOLVED_LIMIT, $agentDefinitionId);

        $whyCache = [];
        $now = [];
        $watch = [];
        foreach ($pending as $row) {
            $row = $this->decorate($row);
            $urgency = BriefUrgencyClassifier::classify($row['targetType'], $row['review']['rating'] ?? null);
            $card = $this->buildCard($row, $canApprove, $whyCache);
            if ($urgency === BriefUrgencyClassifier::NOW) {
                $now[] = $card;
            } else {
                $watch[] = $card;
            }
        }

        $info = array_map(
            fn (array $row): array => $this->buildCard($this->decorate($row), $canApprove, $whyCache),
            $resolved,
        );

        $since = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
        $appliedThisMonth = $this->repository->countAppliedSince($since);
        $budget = $this->budgetGuard->status();

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/merchant-chat/brief.html.twig', [
            'groups' => [
                'now' => $now,
                'watch' => $watch,
                'info' => $info,
            ],
            'header' => [
                'pendingCount' => \count($pending),
                'minutesSavedLabel' => '~'.($appliedThisMonth * self::ESTIMATED_MINUTES_PER_CHANGE).' min',
                // Real LLM spend is tracked in USD (see BudgetGuard/AdminHookManager::formatUsd),
                // unlike MYO-469's mockup which used € as a placeholder currency.
                'budgetIsLimited' => $budget->isLimited(),
                'budgetSpentLabel' => self::formatUsd($budget->spent),
                'budgetLimitLabel' => $budget->isLimited() ? self::formatUsd($budget->budget) : null,
                'budgetPercentUsed' => $budget->percentUsed(),
                'budgetState' => $budget->state(),
            ],
            'agentId' => $agentDefinitionId,
            'canApprove' => $canApprove,
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            // Skills library tab (MYO-506, AC6 of MYO-469 / MYO-507 on the Twig side):
            // server-derived state, never a hardcoded number (guardrail #2). Reuses the
            // same `csrfToken` as the change approve/reject forms above -- brief.html.twig
            // (MYO-507) posts the skill toggle with it, not a separate token.
            'skills' => $this->skillCatalog->all(),
            'usdToEurRate' => ModelCatalog::usdToEurRate(),
            'usdToEurRatedAt' => ModelCatalog::usdToEurRatedAt(),
            // Guided settings card (MYO-508 AC2): shared across every skill's modal, not
            // per-skill data -- ScopeCatalog::appendCategoryScope()/appendCustomerScope()
            // are write-only (never re-read to pre-fill), so these lists are always the
            // same fresh catalog regardless of which skill's modal opens.
            'guidedCategories' => $this->guidedCategories($request->getLocale()),
            'guidedCustomerScopes' => array_map(
                static fn (string $code): array => ['code' => $code, 'labelKey' => ScopeCatalog::customerScopeLabelKey($code)],
                ScopeCatalog::customerScopes(),
            ),
        ]));
    }

    /**
     * Adds the read model the template needs for a typed, human-readable
     * rendering (MYO-324 §1): the original customer review for `review_reply`
     * proposals, fetched live so it reflects the review as it stands today.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decorate(array $row): array
    {
        if ($row['targetType'] === 'review_reply') {
            $row['review'] = $this->reviewsGateway->findReview($row['targetId']);
        }

        return $row;
    }

    /**
     * Builds one Brief card's view model: the trigger in plain language, the
     * drafted proposal, the inline edit field (MYO-472 AC4), and the "Why?"
     * panel content (MYO-472 AC3). Kept in PHP -- like the former
     * `toSuggestion()` -- so the template stays a dumb renderer.
     *
     * @param array<string, mixed> $row
     * @param array<int|string, array{tools: list<string>, runUrl: ?string}> $whyCache keyed by conversationId (or
     *                                                                                  'none'), avoids re-querying tools/run for changes that share a conversation
     *
     * @return array<string, mixed>
     */
    private function buildCard(array $row, bool $canApprove, array &$whyCache): array
    {
        $ref = $row['payloadBefore']['pseRef'] ?? ('#'.$row['targetId']);
        $review = $row['review'] ?? null;

        [$trigger, $proposal, $editField, $whyData, $context] = match ($row['targetType']) {
            'pse_price' => [
                $this->translator->trans('Price adjustment proposed for %ref%', ['%ref%' => $ref], 'commerceagents'),
                $this->translator->trans('Change the price of %ref% from %before% to %after%.', [
                    '%ref%' => $ref,
                    '%before%' => $this->formatPrice($row['payloadBefore']['price'] ?? null),
                    '%after%' => $this->formatPrice($row['payloadAfter']['price'] ?? null),
                ], 'commerceagents'),
                [
                    'name' => 'edited_price',
                    'type' => 'number',
                    'step' => '0.01',
                    'value' => $row['payloadAfter']['price'] ?? null,
                    'label' => $this->translator->trans('New price (€)', [], 'commerceagents'),
                ],
                [
                    $this->translator->trans('Product reference: %ref%', ['%ref%' => $ref], 'commerceagents'),
                    $this->translator->trans('Current price: %price%', ['%price%' => $this->formatPrice($row['payloadBefore']['price'] ?? null)], 'commerceagents'),
                ],
                null,
            ],
            'pse_stock' => [
                $this->translator->trans('Stock correction needed for %ref%', ['%ref%' => $ref], 'commerceagents'),
                $this->translator->trans('Update the stock of %ref% from %before% to %after% units.', [
                    '%ref%' => $ref,
                    '%before%' => $row['payloadBefore']['quantity'] ?? '?',
                    '%after%' => $row['payloadAfter']['quantity'] ?? '?',
                ], 'commerceagents'),
                [
                    'name' => 'edited_quantity',
                    'type' => 'number',
                    'step' => '1',
                    'value' => $row['payloadAfter']['quantity'] ?? null,
                    'label' => $this->translator->trans('New quantity', [], 'commerceagents'),
                ],
                [
                    $this->translator->trans('Product reference: %ref%', ['%ref%' => $ref], 'commerceagents'),
                    $this->translator->trans('Current stock: %quantity% units', ['%quantity%' => $row['payloadBefore']['quantity'] ?? '?'], 'commerceagents'),
                ],
                null,
            ],
            'review_reply' => [
                $review !== null
                    ? $this->translator->trans('Rating %rating%/5 review on %product%', [
                        '%rating%' => $review['rating'] ?? '?',
                        '%product%' => $review['productTitle'] ?? $this->translator->trans('an unknown product', [], 'commerceagents'),
                    ], 'commerceagents')
                    : $this->translator->trans('Review #%id% (deleted)', ['%id%' => $row['targetId']], 'commerceagents'),
                // Agent-drafted, shop-language content: not a catalog label, rendered as-is (see MYO-469 mockup note).
                $row['payloadAfter']['reply'] ?? '',
                [
                    'name' => 'reply_content',
                    'type' => 'textarea',
                    'value' => $row['payloadAfter']['reply'] ?? '',
                    'label' => $this->translator->trans('Edit the draft before approving', [], 'commerceagents'),
                ],
                $review !== null ? [
                    $this->translator->trans('Customer review #%id% (%rating%/5)', ['%id%' => $review['id'], '%rating%' => $review['rating'] ?? '?'], 'commerceagents'),
                    $this->translator->trans('By %author%', ['%author%' => $review['author']], 'commerceagents'),
                ] : [
                    $this->translator->trans('This review no longer exists.', [], 'commerceagents'),
                ],
                // The original customer wording, quoted verbatim -- the merchant needs to see exactly
                // what was said, not just the agent's paraphrase in the trigger line (MYO-324 §1).
                $review['content'] ?? ($row['payloadBefore']['content'] ?? null),
            ],
            default => [
                $this->translator->trans('Item #%id%', ['%id%' => $row['targetId']], 'commerceagents'),
                json_encode($row['payloadBefore'], \JSON_UNESCAPED_UNICODE),
                null,
                [json_encode($row['payloadBefore'], \JSON_UNESCAPED_UNICODE)],
                null,
            ],
        };

        $cacheKey = $row['conversationId'] ?? 'none';
        if (!isset($whyCache[$cacheKey])) {
            $whyCache[$cacheKey] = $this->whyContext($row['conversationId'] ?? null);
        }
        $whyTools = $whyCache[$cacheKey];

        return [
            'id' => $row['id'],
            'targetType' => $row['targetType'],
            'status' => $row['status'],
            'error' => $row['error'],
            'agentTitle' => $row['agentTitle'],
            'canDecide' => $canApprove && $row['status'] === StagedChangeData::STATUS_PENDING,
            'trigger' => $trigger,
            'context' => $context,
            'proposal' => $proposal,
            'editField' => $editField,
            'why' => [
                'data' => $whyData,
                'tools' => $whyTools['tools'],
                'runUrl' => $whyTools['runUrl'],
            ],
        ];
    }

    /**
     * "Pourquoi ?" panel data (MYO-472 AC3): the tools the agent actually
     * called and a link to the full run, both read from real audit data
     * (`agent_action_log` / `agent_run`) via the conversation the staged
     * change was produced in -- the same join `AgentRunsController::show()`
     * already relies on.
     *
     * @return array{tools: list<string>, runUrl: ?string}
     */
    private function whyContext(?int $conversationId): array
    {
        if ($conversationId === null) {
            return ['tools' => [], 'runUrl' => null];
        }

        $toolNames = AgentActionLogQuery::create()
            ->filterByConversationId($conversationId)
            ->select('ToolName')
            ->find()
            ->getData();

        $run = AgentRunQuery::create()
            ->filterByConversationId($conversationId)
            ->orderById(Criteria::DESC)
            ->findOne();

        return [
            'tools' => array_values(array_unique($toolNames)),
            'runUrl' => $run !== null ? $this->urlGenerator->generate('commerceagents_agents_runs_show', ['id' => $run->getId()]) : null,
        ];
    }

    /**
     * Suggestions popup data (MYO-237 §2, §5): pending price/stock proposals
     * for one agent, formatted server-side (title/body) so the popup JS has
     * no business logic to duplicate.
     */
    #[Route('/admin/merchant-agent/changes/agent/{agentDefinitionId}/suggestions', name: 'commerceagents_change_suggestions', methods: ['GET'], requirements: ['agentDefinitionId' => '\d+'])]
    public function suggestions(int $agentDefinitionId): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $suggestions = array_map(
            fn (array $row): array => $this->toSuggestion($row),
            $this->repository->findPendingForAgent($agentDefinitionId),
        );

        return new JsonResponse(['suggestions' => $suggestions]);
    }

    #[Route('/admin/merchant-agent/changes/{id}/approve', name: 'commerceagents_change_approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function approve(int $id, Request $request): Response
    {
        return $this->handleDecision($id, $request, approve: true);
    }

    #[Route('/admin/merchant-agent/changes/{id}/reject', name: 'commerceagents_change_reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reject(int $id, Request $request): Response
    {
        return $this->handleDecision($id, $request, approve: false);
    }

    private function handleDecision(int $id, Request $request, bool $approve): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::UPDATE)) {
            return $denied;
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'reason' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
            }

            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        $change = $this->repository->find($id);
        $nativeResource = $change !== null ? (self::NATIVE_RESOURCE_BY_TARGET_TYPE[$change->targetType] ?? null) : null;
        if ($nativeResource !== null && ($denied = $this->access->check([$nativeResource], [], AccessManager::UPDATE))) {
            return $denied;
        }

        // Lets the merchant amend the agent's draft before approving it
        // (MYO-324 §2, generalized to pse_price/pse_stock by MYO-472 AC4).
        if ($approve && $change !== null && $change->status === StagedChangeData::STATUS_PENDING) {
            $edited = $this->extractEditedPayload($change->targetType, $request, $change->payloadAfter);
            if ($edited === self::EDIT_INVALID) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['success' => false, 'reason' => 'invalid_edit', 'message' => 'Edited value is required and must be valid'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                return new RedirectResponse($this->urlGenerator->generate('commerceagents_changes'));
            }

            if ($edited !== null && $edited !== $change->payloadAfter) {
                $this->repository->updatePayloadAfter($id, $edited);
            }
        }

        $adminId = (int) $this->securityContext->getAdminUser()->getId();

        $result = $approve ? $this->manager->approve($id, $adminId) : $this->manager->reject($id, $adminId);

        if ($request->isXmlHttpRequest()) {
            // The manager is idempotent by design: re-approving/rejecting an
            // already-decided change never double-applies, it just returns
            // an "is not pending" error (StagedChangeManager::approve/reject).
            // That specific case is the popup's "already handled elsewhere"
            // state (MYO-237 §4), not a hard failure.
            if (isset($result['error'])) {
                $alreadyHandled = str_contains($result['error'], 'is not pending');

                return new JsonResponse(
                    ['success' => false, 'reason' => $alreadyHandled ? 'already_handled' : 'error', 'message' => $result['error']],
                    $alreadyHandled ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }

            return new JsonResponse(['success' => true, 'status' => $result['status'] ?? null]);
        }

        return new RedirectResponse($this->urlGenerator->generate('commerceagents_changes'));
    }

    /**
     * Reads the edit field submitted with the approve form, if any, and
     * merges it into the current payload_after (MYO-472 AC4). Returns null
     * when no edit was submitted (approve the draft as-is), or
     * {@see self::EDIT_INVALID} when one was submitted but is not usable.
     *
     * @param array<string, mixed> $currentPayloadAfter
     *
     * @return array<string, mixed>|string|null
     */
    private function extractEditedPayload(string $targetType, Request $request, array $currentPayloadAfter): array|string|null
    {
        return match ($targetType) {
            'review_reply' => $this->extractEditedText($request, 'reply_content', 'reply', $currentPayloadAfter),
            'pse_price' => $this->extractEditedNumber($request, 'edited_price', 'price', $currentPayloadAfter, allowDecimal: true),
            'pse_stock' => $this->extractEditedNumber($request, 'edited_quantity', 'quantity', $currentPayloadAfter, allowDecimal: false),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $currentPayloadAfter
     *
     * @return array<string, mixed>|string|null
     */
    private function extractEditedText(Request $request, string $fieldName, string $payloadKey, array $currentPayloadAfter): array|string|null
    {
        if (!$request->request->has($fieldName)) {
            return null;
        }

        $value = trim((string) $request->request->get($fieldName));
        if ($value === '') {
            return self::EDIT_INVALID;
        }

        return [...$currentPayloadAfter, $payloadKey => $value];
    }

    /**
     * @param array<string, mixed> $currentPayloadAfter
     *
     * @return array<string, mixed>|string|null
     */
    private function extractEditedNumber(Request $request, string $fieldName, string $payloadKey, array $currentPayloadAfter, bool $allowDecimal): array|string|null
    {
        if (!$request->request->has($fieldName)) {
            return null;
        }

        $raw = trim((string) $request->request->get($fieldName));
        if ($raw === '' || !is_numeric($raw) || (float) $raw < 0) {
            return self::EDIT_INVALID;
        }

        return [...$currentPayloadAfter, $payloadKey => $allowDecimal ? (float) $raw : (int) $raw];
    }

    /**
     * @param array{id: int, targetType: string, targetId: int, payloadBefore: array, payloadAfter: array, createdAt: ?string} $row
     *
     * @return array<string, mixed>
     */
    private function toSuggestion(array $row): array
    {
        $ref = $row['payloadBefore']['pseRef'] ?? ('#'.$row['targetId']);

        return match ($row['targetType']) {
            'pse_price' => [
                'id' => $row['id'],
                'targetType' => 'pse_price',
                'icon' => 'bi-tag',
                'accent' => 'warning',
                'title' => $this->translator->trans('Price to adjust: %ref%', ['%ref%' => $ref], 'commerceagents'),
                'body' => $this->translator->trans('%before% → %after%', [
                    '%before%' => $this->formatPrice($row['payloadBefore']['price'] ?? null),
                    '%after%' => $this->formatPrice($row['payloadAfter']['price'] ?? null),
                ], 'commerceagents'),
                'ctaLabel' => $this->translator->trans('Approve', [], 'commerceagents'),
                'createdAt' => $row['createdAt'],
            ],
            'pse_stock' => [
                'id' => $row['id'],
                'targetType' => 'pse_stock',
                'icon' => 'bi-box-seam',
                'accent' => 'danger',
                'title' => $this->translator->trans('Stock to correct: %ref%', ['%ref%' => $ref], 'commerceagents'),
                'body' => $this->translator->trans('%before% → %after%', [
                    '%before%' => $row['payloadBefore']['quantity'] ?? '?',
                    '%after%' => $row['payloadAfter']['quantity'] ?? '?',
                ], 'commerceagents'),
                'ctaLabel' => $this->translator->trans('Approve', [], 'commerceagents'),
                'createdAt' => $row['createdAt'],
            ],
            default => [
                'id' => $row['id'],
                'targetType' => $row['targetType'],
                'icon' => 'bi-question-circle',
                'accent' => 'secondary',
                'title' => $ref,
                'body' => '',
                'ctaLabel' => $this->translator->trans('Approve', [], 'commerceagents'),
                'createdAt' => $row['createdAt'],
            ],
        };
    }

    /**
     * Shop categories for the guided settings card's "Categories covered"
     * multi-select (MYO-508 AC2) -- same visible/ordered catalog convention
     * as {@see \CommerceAgents\Service\Shopping\TheliaCategoryGateway}, but
     * every visible category (not just ones with products): a merchant may
     * pick a category ahead of stocking it.
     *
     * @return list<array{id: int, title: string}>
     */
    private function guidedCategories(string $locale): array
    {
        $categories = [];
        foreach (CategoryQuery::create()->filterByVisible(true)->orderByPosition()->find() as $category) {
            $category->setLocale($locale);
            $categories[] = ['id' => (int) $category->getId(), 'title' => $category->getTitle() ?? ''];
        }

        return $categories;
    }

    private function formatPrice(mixed $value): string
    {
        return $value !== null ? number_format((float) $value, 2, ',', ' ').' €' : '?';
    }

    /** Mirrors AdminHookManager::formatUsd() so the Brief header matches the module configuration page's convention. */
    private static function formatUsd(float $amount): string
    {
        $decimals = $amount !== 0.0 && abs($amount) < 0.01 ? 4 : 2;

        return '$'.number_format($amount, $decimals, '.', ' ');
    }
}
