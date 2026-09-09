<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Shopping;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Shopping\Gateway\CustomerGatewayInterface;

final readonly class GetMyProfileTool implements ToolInterface
{
    public function __construct(
        private CustomerGatewayInterface $customerGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_my_profile';
    }

    public function getDescription(): string
    {
        return 'Get the profile of the logged-in customer: name, email, default address and account page link. '
            .'Only available when the customer is logged in. Never returns another customer\'s data.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return !$ctx->isAdmin && $ctx->customerId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        if ($ctx->customerId === null) {
            return ['error' => 'Customer must be logged in to see their profile'];
        }

        $profile = $this->customerGateway->getProfile($ctx->customerId, $ctx->locale);

        if ($profile === null) {
            return ['error' => 'Profile not found'];
        }

        return ['profile' => $profile];
    }
}
