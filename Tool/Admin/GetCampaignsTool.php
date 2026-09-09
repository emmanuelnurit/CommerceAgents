<?php

declare(strict_types=1);

namespace CommerceAgents\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Tool\Admin\Gateway\CampaignGatewayInterface;

final readonly class GetCampaignsTool implements ToolInterface
{
    public function __construct(
        private CampaignGatewayInterface $campaignGateway,
    ) {
    }

    public function getName(): string
    {
        return 'get_campaigns';
    }

    public function getDescription(): string
    {
        return 'List store campaigns: discount coupons and catalog sales, with their dates and status. '
            .'By default only active campaigns; set active_only to false for the full history.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'active_only' => ['type' => 'boolean', 'description' => 'Only active campaigns (default true)'],
            ],
            'required' => [],
        ];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return $ctx->isAdmin && $ctx->adminId !== null;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        $activeOnly = (bool) ($args['active_only'] ?? true);

        return ['campaigns' => $this->campaignGateway->getCampaigns($activeOnly, $ctx)];
    }
}
