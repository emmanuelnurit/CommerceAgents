<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;

final readonly class SessionFreezer
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Replaces the request session with an in-memory copy.
     *
     * The kernel saves and closes the native session when response headers are
     * sent — before a StreamedResponse callback runs. Any session read after
     * that restarts the native session and fails with "headers already sent".
     * Freezing gives every mid-stream reader (TaxEngine, SecurityContext,
     * DataAccessService…) a detached copy instead. Mid-stream session WRITES
     * land in the copy and are lost: warm up anything that must persist (the
     * session cart in particular) before calling this.
     */
    public function freeze(): void
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return;
        }

        $real = $request->getSession();
        if (!$real->isStarted()) {
            $real->start();
        }

        $frozen = new Session(new MockArraySessionStorage());
        $frozen->replace($real->all());

        $request->setSession($frozen);
    }
}
