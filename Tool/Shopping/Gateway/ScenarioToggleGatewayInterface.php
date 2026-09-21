<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping\Gateway;

/**
 * Config-backed on/off switches for individual proactive scenarios, kept
 * out of the resolvers themselves (same discipline as the other gateways:
 * resolvers stay unit-testable without a DB connection).
 */
interface ScenarioToggleGatewayInterface
{
    /**
     * MYO-475/MYO-471 T7: off by default. See
     * AgentConfigService::isNewsletterOptinScenarioEnabled() for why.
     */
    public function isNewsletterOptinScenarioEnabled(): bool;
}
