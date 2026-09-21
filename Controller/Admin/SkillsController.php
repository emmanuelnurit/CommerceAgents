<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Service\SkillCatalog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;

/**
 * Activate/deactivate a skill from the "Bibliothèque de skills" tab of "Le
 * Brief" (MYO-506, AC6 of MYO-469; Twig side MYO-507). Same admin write
 * access as the other `commerceagents` write routes; same CSRF token as the
 * page that posts here -- brief.html.twig's `skillTile` macro (MYO-507)
 * submits the toggle form with the page's own `csrfToken`
 * ({@see StagedChangesController::CSRF_TOKEN_ID}), not a separate one.
 */
final readonly class SkillsController
{
    public function __construct(
        private AdminAccessChecker $access,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private SkillCatalog $skillCatalog,
    ) {
    }

    #[Route('/admin/module/CommerceAgents/skills/{code}/activate', name: 'commerceagents_skill_activate', methods: ['POST'])]
    public function activate(string $code, Request $request): Response
    {
        return $this->handle($code, $request, activate: true);
    }

    #[Route('/admin/module/CommerceAgents/skills/{code}/deactivate', name: 'commerceagents_skill_deactivate', methods: ['POST'])]
    public function deactivate(string $code, Request $request): Response
    {
        return $this->handle($code, $request, activate: false);
    }

    private function handle(string $code, Request $request, bool $activate): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            $activate ? $this->skillCatalog->activate($code) : $this->skillCatalog->deactivate($code);
        } catch (\RuntimeException $exception) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new RedirectResponse($this->urlGenerator->generate('commerceagents_changes'));
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        return new RedirectResponse($this->urlGenerator->generate('commerceagents_changes'));
    }

    private function guard(Request $request): ?Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::UPDATE)) {
            return $denied;
        }

        $token = new CsrfToken(StagedChangesController::CSRF_TOKEN_ID, (string) $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['success' => false, 'reason' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
            }

            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
