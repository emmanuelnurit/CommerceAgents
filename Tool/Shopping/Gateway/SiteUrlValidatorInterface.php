<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

interface SiteUrlValidatorInterface
{
    /**
     * Whether the URL belongs to this store (same host, or site-relative path).
     */
    public function isSiteUrl(string $url): bool;
}
