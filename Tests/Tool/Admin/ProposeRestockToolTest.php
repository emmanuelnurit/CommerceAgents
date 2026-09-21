<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\ProposeRestockTool;
use PHPUnit\Framework\TestCase;

class ProposeRestockToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1, conversationId: 7);
    }

    private function velocityGateway(array $overrides = []): FakeSalesVelocityGateway
    {
        return new FakeSalesVelocityGateway(array_merge([
            'pseId' => 12,
            'pseRef' => 'BUR-OSLO-01',
            'productId' => 6,
            'productRef' => 'PROD006',
            'quantity' => 4.0,
            'weeks' => 4,
            'unitsSold' => 24.0,
            'velocityPerWeek' => 6.0,
            'windowStart' => '2026-08-24',
            'windowEnd' => '2026-09-21',
        ], $overrides));
    }

    public function testGateRequiresAdmin(): void
    {
        $tool = new ProposeRestockTool($this->velocityGateway(), new FakeCampaignGateway(), new FakeStagingGateway());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
    }

    public function testProposesRestockWithTheFourExpectedFigures(): void
    {
        $velocityGateway = $this->velocityGateway();
        $campaignGateway = new FakeCampaignGateway(activeSaleProductIds: [6]);
        $stagingGateway = new FakeStagingGateway(['changeId' => 99, 'status' => 'pending']);
        $tool = new ProposeRestockTool($velocityGateway, $campaignGateway, $stagingGateway);

        $result = $tool->execute(['pse_id' => 12], $this->adminContext());

        $proposal = $result['proposal'];
        $this->assertSame(4.0, $proposal['currentStock']);
        $this->assertSame(6.0, $proposal['velocityPerWeek']);
        $this->assertNotNull($proposal['estimatedStockoutDate']);
        $this->assertSame(32, $proposal['proposedQuantity']);
        $this->assertTrue($proposal['onActiveCampaign']);

        $this->assertSame('stageRestockProposal', $stagingGateway->lastCall[0]);
        $this->assertSame(12, $stagingGateway->lastCall[1]);
        $this->assertSame(36.0, $stagingGateway->lastCall[2]);
        $this->assertSame(99, $result['staged_change']['changeId']);
        $this->assertStringContainsString('requires human approval', $result['message']);
    }

    public function testNotOnActiveCampaignIsReported(): void
    {
        $tool = new ProposeRestockTool($this->velocityGateway(), new FakeCampaignGateway(), new FakeStagingGateway());

        $result = $tool->execute(['pse_id' => 12], $this->adminContext());

        $this->assertFalse($result['proposal']['onActiveCampaign']);
    }

    public function testNoRestockProposedWhenStockAlreadyCoversTheWindow(): void
    {
        $velocityGateway = $this->velocityGateway(['quantity' => 100.0]);
        $stagingGateway = new FakeStagingGateway();
        $tool = new ProposeRestockTool($velocityGateway, new FakeCampaignGateway(), $stagingGateway);

        $result = $tool->execute(['pse_id' => 12], $this->adminContext());

        $this->assertSame(0, $result['proposal']['proposedQuantity']);
        $this->assertArrayNotHasKey('staged_change', $result);
        $this->assertSame([], $stagingGateway->lastCall);
    }

    public function testNoStockoutDateWhenVelocityIsZero(): void
    {
        $velocityGateway = $this->velocityGateway(['velocityPerWeek' => 0.0]);
        $tool = new ProposeRestockTool($velocityGateway, new FakeCampaignGateway(), new FakeStagingGateway());

        $result = $tool->execute(['pse_id' => 12], $this->adminContext());

        $this->assertNull($result['proposal']['estimatedStockoutDate']);
        $this->assertSame(0, $result['proposal']['proposedQuantity']);
    }

    public function testVelocityGatewayErrorIsPassedThrough(): void
    {
        $velocityGateway = new FakeSalesVelocityGateway(['error' => 'Variant not found']);
        $tool = new ProposeRestockTool($velocityGateway, new FakeCampaignGateway(), new FakeStagingGateway());

        $result = $tool->execute(['pse_id' => 999], $this->adminContext());

        $this->assertSame('Variant not found', $result['error']);
    }

    public function testStagingGatewayErrorIsPassedThrough(): void
    {
        $stagingGateway = new FakeStagingGateway(['error' => 'No conversation context']);
        $tool = new ProposeRestockTool($this->velocityGateway(), new FakeCampaignGateway(), $stagingGateway);

        $result = $tool->execute(['pse_id' => 12], $this->adminContext());

        $this->assertSame('No conversation context', $result['error']);
    }

    public function testSchema(): void
    {
        $schema = (new ProposeRestockTool($this->velocityGateway(), new FakeCampaignGateway(), new FakeStagingGateway()))->getInputSchema();

        $this->assertSame(['pse_id'], $schema['required']);
    }
}
