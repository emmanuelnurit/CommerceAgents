<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Channel\Connector;

use CommerceAgents\Service\Security\OutboundUrlValidatorInterface;

final class FakeOutboundUrlValidator implements OutboundUrlValidatorInterface
{
    public function __construct(private readonly bool $allowed = true)
    {
    }

    public function isAllowed(string $url): bool
    {
        return $this->allowed;
    }
}
