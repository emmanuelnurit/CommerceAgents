<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Security;

/**
 * Guards every admin-configurable outbound URL this module dials out to
 * (LLM provider base_url, webhook channel target) against SSRF (MYO-276):
 * https-only, and every IP the host resolves to must be public -- never
 * loopback, private (RFC1918), link-local (including the 169.254.169.254
 * cloud metadata address), or otherwise reserved.
 *
 * DNS-rebinding note: resolution happens here, at validation time, not at
 * request time, so a host that resolves to a public IP now and a private one
 * a few seconds later is not caught by this check alone. Closing that gap
 * needs the HTTP client itself to refuse private IPs per-connection (e.g.
 * Symfony's NoPrivateNetworkHttpClient) -- tracked as a follow-up hardening
 * item, not required for the exploitable path this closes today (an admin
 * pointing base_url straight at an internal address).
 */
final class OutboundUrlValidator implements OutboundUrlValidatorInterface
{
    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        $host = trim($parts['host'], '[]');

        if (filter_var($host, \FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if (!\is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
