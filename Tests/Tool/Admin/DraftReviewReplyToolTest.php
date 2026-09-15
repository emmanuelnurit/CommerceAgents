<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Admin;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Tool\Admin\DraftReviewReplyTool;
use PHPUnit\Framework\TestCase;

class DraftReviewReplyToolTest extends TestCase
{
    private function adminContext(): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 1);
    }

    public function testGateRequiresAdmin(): void
    {
        $tool = new DraftReviewReplyTool(new FakeStagingGateway(), new FakeModuleAvailability());

        $this->assertTrue($tool->isAllowed($this->adminContext()));
        $this->assertFalse($tool->isAllowed(new ToolContext(isAdmin: false)));
    }

    public function testRefusesWhenCommentModuleIsInactive(): void
    {
        $gateway = new FakeStagingGateway();
        $tool = new DraftReviewReplyTool($gateway, new FakeModuleAvailability(active: false));

        $result = $tool->execute(['review_id' => 1, 'reply' => 'Merci !'], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
    }

    public function testStagesReplyAndAnnouncesApproval(): void
    {
        $gateway = new FakeStagingGateway(['changeId' => 7, 'status' => 'pending']);
        $tool = new DraftReviewReplyTool($gateway, new FakeModuleAvailability());

        $result = $tool->execute(['review_id' => 12, 'reply' => 'Merci pour votre retour !'], $this->adminContext());

        $this->assertSame(['stageReviewReply', 12, 'Merci pour votre retour !'], $gateway->lastCall);
        $this->assertSame(7, $result['staged_change']['changeId']);
        $this->assertStringContainsString('requires human approval', $result['message']);
    }

    public function testEmptyReplyIsRefusedWithoutStaging(): void
    {
        $gateway = new FakeStagingGateway();
        $result = (new DraftReviewReplyTool($gateway, new FakeModuleAvailability()))
            ->execute(['review_id' => 12, 'reply' => '   '], $this->adminContext());

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $gateway->lastCall);
    }

    public function testGatewayErrorIsPassedThrough(): void
    {
        $gateway = new FakeStagingGateway(['error' => 'Review not found']);
        $result = (new DraftReviewReplyTool($gateway, new FakeModuleAvailability()))
            ->execute(['review_id' => 999, 'reply' => 'Merci !'], $this->adminContext());

        $this->assertSame('Review not found', $result['error']);
    }

    public function testSchema(): void
    {
        $schema = (new DraftReviewReplyTool(new FakeStagingGateway(), new FakeModuleAvailability()))->getInputSchema();

        $this->assertSame(['review_id', 'reply'], $schema['required']);
    }
}
