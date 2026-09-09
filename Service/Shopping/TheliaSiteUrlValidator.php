<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Tool\Shopping\Gateway\SiteUrlValidatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class TheliaSiteUrlValidator implements SiteUrlValidatorInterface
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function isSiteUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $host = $this->requestStack->getMainRequest()?->getHost();
        if ($host === null) {
            return false;
        }

        $parsed = parse_url($url);

        return \in_array($parsed['scheme'] ?? '', ['http', 'https'], true)
            && ($parsed['host'] ?? '') === $host;
    }
}
