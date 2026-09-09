<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\Gateway\CampaignGatewayInterface;
use CommerceAgents\Tool\Admin\GetCampaignsTool;
use PHPUnit\Framework\TestCase;

class FakeCampaignGateway implements CampaignGatewayInterface
{
    public array $lastCall = [];

    public function __construct(private readonly array $campaigns = ['coupons' => [], 'sales' => []])
    {
    }

    public function getCampaigns(bool $activeOnly, ToolContext $ctx): array
    {
        $this->lastCall = ['activeOnly' => $activeOnly];

        return $this->campaigns;
    }
}

class GetCampaignsToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdmin(): void
    {
        $tool = new GetCampaignsTool(new FakeCampaignGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
    }

    public function testActiveOnlyDefaultsTrue(): void
    {
        $gateway = new FakeCampaignGateway(['coupons' => [['code' => 'WELCOME10']], 'sales' => []]);
        $tool = new GetCampaignsTool($gateway);

        $result = $tool->execute([], $this->adminContext());
        $this->assertTrue($gateway->lastCall['activeOnly']);
        $this->assertSame('WELCOME10', $result['campaigns']['coupons'][0]['code']);

        $tool->execute(['active_only' => false], $this->adminContext());
        $this->assertFalse($gateway->lastCall['activeOnly']);
    }
}
