<?php

declare(strict_types=1);

namespace CommerceAgents\Controller\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use CommerceAgents\Service\Notification\NotificationCenterService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;

/**
 * Data layer of the notification center topbar (MYO-481/483, this endpoint
 * pair MYO-484): the hook/template rendering the dropdown is a sister
 * ticket's responsibility, this controller only ever returns/consumes JSON.
 */
final readonly class NotificationCenterController
{
    private const VALID_SOURCE_TYPES = [
        NotificationCenterService::TYPE_STAGED_CHANGE,
        NotificationCenterService::TYPE_OUTBOUND_MESSAGE,
    ];

    /**
     * Same mechanism as MerchantChatController::CSRF_TOKEN_ID/CSRF_HEADER
     * (MYO-284 M4): ack() posts a JSON body from fetch(), not a classic form
     * submit, so the token travels as a header instead of a hidden `_token`
     * field. This controller has no GET-rendered template of its own to
     * embed the hidden field in (the hook/template is the sister ticket's),
     * so the token is exposed on the summary() JSON response instead --
     * the front reads it from there before calling ack().
     */
    public const CSRF_TOKEN_ID = 'commerceagents_notifications';
    public const CSRF_HEADER = 'X-CSRF-Token';

    public function __construct(
        private AdminAccessChecker $access,
        private SecurityContext $securityContext,
        private NotificationCenterService $notificationCenter,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/admin/merchant-agent/notifications', name: 'commerceagents_notifications', methods: ['GET'])]
    public function summary(): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $adminId = (int) $this->securityContext->getAdminUser()->getId();

        return new JsonResponse([
            ...$this->notificationCenter->summaryForAdmin($adminId),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);
    }

    #[Route('/admin/merchant-agent/notifications/ack', name: 'commerceagents_notifications_ack', methods: ['POST'])]
    public function ack(Request $request): Response
    {
        if ($denied = $this->access->check([], 'commerceagents', AccessManager::VIEW)) {
            return $denied;
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get(self::CSRF_HEADER));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new JsonResponse(['success' => false, 'reason' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $sourceType = $body['sourceType'] ?? null;
        $sourceId = $body['sourceId'] ?? null;

        if (!\in_array($sourceType, self::VALID_SOURCE_TYPES, true) || !\is_numeric($sourceId)) {
            return new JsonResponse(['success' => false, 'reason' => 'invalid_payload'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $adminId = (int) $this->securityContext->getAdminUser()->getId();
        $this->notificationCenter->ack($adminId, $sourceType, (int) $sourceId);

        return new JsonResponse(['success' => true]);
    }
}
