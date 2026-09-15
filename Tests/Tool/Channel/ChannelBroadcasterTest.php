<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Channel;

use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Tool\Channel\ChannelBroadcaster;
use PHPUnit\Framework\TestCase;

/**
 * MYO-335: ChannelBroadcaster is the fan-out-and-log logic extracted out of
 * SendToChannelTool so a BO-triggered resend goes through the exact same
 * delivery/audit path (see SendToChannelToolTest for the tool-level
 * coverage of this same behaviour; the fakes are shared from that file).
 */
final class ChannelBroadcasterTest extends TestCase
{
    private function context(): ToolContext
    {
        return new ToolContext(isAdmin: true, agentDefinitionId: 7, channel: ToolContext::CHANNEL_RUN, agentRunId: 99);
    }

    public function testReturnsAnErrorWhenNoChannelIsConfigured(): void
    {
        $broadcaster = new ChannelBroadcaster(new FakeChannelGateway([]), new ChannelConnectorRegistry());

        $outcome = $broadcaster->broadcast(7, new ChannelMessage(null, 'hello'), $this->context());

        $this->assertSame('No channel configured for this agent', $outcome['error']);
        $this->assertSame([], $outcome['results']);
    }

    public function testSendsDirectlyThroughTheConnectorAndLogsIt(): void
    {
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);
        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $outboundLogger = new FakeOutboundMessageLogger();
        $broadcaster = new ChannelBroadcaster($gateway, $registry, $outboundLogger);

        $outcome = $broadcaster->broadcast(7, new ChannelMessage('Rapport', 'Corps du rapport'), $this->context());

        $this->assertSame('sent', $outcome['results'][0]['status']);
        $this->assertSame('Corps du rapport', $connector->sent[0]->body);
        $this->assertSame('sent', $outboundLogger->logged[0]['status']);
    }

    public function testReportsAConnectorFailurePerChannelWithoutThrowing(): void
    {
        $connector = new FakeDirectConnector();
        $connector->throwOnSend = new ChannelException('Timeout');
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);
        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => []],
        ]);
        $outboundLogger = new FakeOutboundMessageLogger();
        $broadcaster = new ChannelBroadcaster($gateway, $registry, $outboundLogger);

        $outcome = $broadcaster->broadcast(7, new ChannelMessage(null, 'hello'), $this->context());

        $this->assertSame('Timeout', $outcome['results'][0]['error']);
        $this->assertSame('failed', $outboundLogger->logged[0]['status']);
    }

    public function testStagesADraftModeChannelInsteadOfSendingIt(): void
    {
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);
        $gateway = new FakeChannelGateway([
            ['id' => 2, 'connectorCode' => 'webhook', 'mode' => 'draft', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $broadcaster = new ChannelBroadcaster($gateway, $registry);

        $outcome = $broadcaster->broadcast(7, new ChannelMessage(null, 'hello'), $this->context());

        $this->assertSame([], $connector->sent);
        $this->assertCount(1, $gateway->staged);
        $this->assertSame('pending', $outcome['results'][0]['status']);
    }
}
