<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Security;

/**
 * Extracted so tests exercising callers (WebhookChannelConnector,
 * ConfigSaveController) can substitute a fake instead of doing real DNS
 * resolution against fixture/example hostnames.
 */
interface OutboundUrlValidatorInterface
{
    public function isAllowed(string $url): bool;
}
