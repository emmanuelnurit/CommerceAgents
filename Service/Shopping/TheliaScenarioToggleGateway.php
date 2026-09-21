<?php

declare(strict_types=1);

namespace CommerceAgents\Service\Shopping;

use CommerceAgents\Service\AgentConfigService;
use CommerceAgents\Tool\Shopping\Gateway\ScenarioToggleGatewayInterface;

final readonly class TheliaScenarioToggleGateway implements ScenarioToggleGatewayInterface
{
    public function __construct(
        private AgentConfigService $configService,
    ) {
    }

    public function isNewsletterOptinScenarioEnabled(): bool
    {
        return $this->configService->isNewsletterOptinScenarioEnabled();
    }
}
