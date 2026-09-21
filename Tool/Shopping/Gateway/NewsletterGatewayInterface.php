<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface NewsletterGatewayInterface
{
    /**
     * Whether this e-mail already has an active (non-unsubscribed) row in
     * the real `newsletter` table.
     */
    public function isSubscribed(string $email): bool;
}
