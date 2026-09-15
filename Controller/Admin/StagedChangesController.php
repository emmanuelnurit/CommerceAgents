<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Service\Merchant\TheliaStagedChangeRepository;
use CommerceAgents\StagedChange\StagedChangeManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Twig\Environment;

final readonly class StagedChangesController
{
    public const CSRF_TOKEN_ID = 'commerceagents_changes';

    public function __construct(
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private TheliaStagedChangeRepository $repository,
        private StagedChangeManager $manager,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
        private Translator $translator,
    ) {
    }

    #[Route('/admin/merchant-agent/changes', name: 'commerceagents_changes', methods: ['GET'])]
    public function list(): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        return new Response($this->twig->render('@CommerceAgentsModule/backOffice/default-twig/merchant-chat/changes.html.twig', [
            'changes' => $this->repository->findRecent(50),
            'canApprove' => $this->securityContext->isGranted(['ADMIN'], [], ['commerceagents'], [AccessManager::UPDATE]),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
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

    private function formatPrice(mixed $value): string
    {
        return $value !== null ? number_format((float) $value, 2, ',', ' ').' €' : '?';
    }
}
