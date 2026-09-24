<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Service\ModelCatalog;
use CommerceAgents\Service\SkillCatalog;
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
use Thelia\Model\CategoryQuery;

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

    /**
     * Guided settings card save (MYO-508 AC2/AC4) -- ton/détail, seuils,
     * heure, plafond quotidien, budget mensuel, portée catégories/clients.
     * Same admin write access and CSRF token as {@see self::handle()}, and
     * the same idempotent-on-error JSON contract for the fetch()-driven form.
     */
    #[Route('/admin/module/CommerceAgents/skills/{code}/guided-settings', name: 'commerceagents_skill_guided_settings', methods: ['POST'])]
    public function guidedSettings(string $code, Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            $this->skillCatalog->saveGuidedSettings($code, $this->parseGuidedSettingsInput($request));
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

    /**
     * @return array{variant: ?string, threshold: ?int, delayHours: ?int, minAmount: ?float, time: ?string, dailyCap: ?int, monthlyBudgetUsd: ?float, categoryTitles: list<string>, customerScope: ?string}
     */
    private function parseGuidedSettingsInput(Request $request): array
    {
        $variant = trim((string) $request->request->get('variant', ''));
        $time = trim((string) $request->request->get('time', ''));
        $customerScope = trim((string) $request->request->get('customer_scope', ''));
        $eurBudget = trim((string) $request->request->get('monthly_budget_eur', ''));

        $categoryIds = array_values(array_filter(array_map('intval', (array) $request->request->all('category_ids'))));

        return [
            'variant' => $variant !== '' ? $variant : null,
            'threshold' => self::intOrNull($request->request->get('threshold')),
            'delayHours' => self::intOrNull($request->request->get('delay_hours')),
            'minAmount' => self::floatOrNull($request->request->get('min_amount')),
            'time' => $time !== '' ? $time : null,
            'dailyCap' => self::intOrNull($request->request->get('daily_cap')),
            'monthlyBudgetUsd' => $eurBudget !== '' ? ModelCatalog::toUsd((float) str_replace(',', '.', $eurBudget), 'EUR') : null,
            'categoryTitles' => $this->categoryTitles($categoryIds, $request->getLocale()),
            'customerScope' => $customerScope !== '' ? $customerScope : null,
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<string>
     */
    private function categoryTitles(array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $titles = [];
        foreach (CategoryQuery::create()->filterById($ids, Criteria::IN)->find() as $category) {
            $category->setLocale($locale);
            $title = $category->getTitle();
            if ($title !== null && $title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    private static function intOrNull(mixed $raw): ?int
    {
        $raw = trim((string) $raw);

        return $raw !== '' && is_numeric($raw) ? (int) $raw : null;
    }

    private static function floatOrNull(mixed $raw): ?float
    {
        $raw = trim(str_replace(',', '.', (string) $raw));

        return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
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
