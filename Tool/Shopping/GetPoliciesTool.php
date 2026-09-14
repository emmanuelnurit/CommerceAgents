<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\PolicyGatewayInterface;

final readonly class GetPoliciesTool implements ToolInterface
{
    public function __construct(
        private PolicyGatewayInterface $policyGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_policies';
    }

    public function getDescription(): string
    {
        return 'Get the store policies: terms of sale, shipping and return conditions.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function getRequiredCapability(): string
    {
        return Capability::CONTENT_READ;
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $policies = $this->policyGateway->getPolicies($ctx->locale);

        if ($policies === []) {
            return ['error' => 'No policy content configured'];
        }

        return ['policies' => $policies];
    }
}
