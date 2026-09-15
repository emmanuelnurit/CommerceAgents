<?php

declare(strict_types=1);

namespace CommerceAgents\EventListener;

use CommerceAgents\Service\Run\PseudoCronGuard;
use CommerceAgents\Service\Run\RunDueService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Thelia\Core\HttpFoundation\Request;

/**
 * Fallback pseudo-cron: when no system cron has ticked `commerce-agents:run-due`
 * recently, a back-office request opportunistically drains the queue instead
 * (plan MYO-226 §3.3 point 4 — documented in the module README as an
 * install-time consideration, not a replacement for a real cron entry).
 *
 * Runs on `kernel.terminate`, after the response has already been sent, so a
 * merchant never waits on it. {@see PseudoCronGuard} keeps it off storefront
 * traffic and off every single admin page load: it only fires once the real
 * cron looks stale, and then at most once per cooldown window.
 */
final readonly class PseudoCronFallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PseudoCronGuard $guard,
        private RunDueService $runDueService,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE => 'onKernelTerminate',
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!Request::$isAdminEnv) {
            return;
        }

        $now = new \DateTimeImmutable();
        if (!$this->guard->shouldRunNow($now)) {
            return;
        }

        $this->guard->markPseudoTick($now);

        try {
            $this->runDueService->run($now);
        } catch (\Throwable $exception) {
            $this->logger->error('[commerce-agents] pseudo-cron fallback failed: '.$exception->getMessage(), [
                'exception' => $exception,
            ]);
        }
    }
}
