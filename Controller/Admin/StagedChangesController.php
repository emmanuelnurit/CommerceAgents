<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Service\Merchant\TheliaStagedChangeRepository;
use CommerceAgents\StagedChange\StagedChangeManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
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
            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        $adminId = (int) $this->securityContext->getAdminUser()->getId();

        if ($approve) {
            $this->manager->approve($id, $adminId);
        } else {
            $this->manager->reject($id, $adminId);
        }

        return new RedirectResponse($this->urlGenerator->generate('commerceagents_changes'));
    }
}
