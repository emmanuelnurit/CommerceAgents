<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Tool\Channel;

use CommerceAgents\Agent\Tool\AgentOutboundMessageLoggerInterface;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Channel\ChannelConnectorInterface;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\ConnectorTestResult;
use CommerceAgents\Tool\Channel\Gateway\ChannelGatewayInterface;
use CommerceAgents\Tool\Channel\SendToChannelTool;
use PHPUnit\Framework\TestCase;

class FakeDirectConnector implements ChannelConnectorInterface
{
    /** @var list<ChannelMessage> */
    public array $sent = [];

    public ?ChannelException $throwOnSend = null;

    public function getCode(): string
    {
        return 'webhook';
    }

    public function getLabel(): string
    {
        return 'Webhook';
    }

    public function getSettingsSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function test(array $settings): ConnectorTestResult
    {
        return ConnectorTestResult::success('ok');
    }

    public function send(ChannelMessage $message, array $settings): void
    {
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }
        $this->sent[] = $message;
    }
}

class FakeChannelGateway implements ChannelGatewayInterface
{
    /** @var list<array{id: int, connectorCode: string, mode: string, settings: array}> */
    public array $channels;

    /** @var list<array{channelId: int, connectorCode: string, message: ChannelMessage}> */
    public array $staged = [];

    public function __construct(array $channels = [])
    {
        $this->channels = $channels;
    }

    public function getEnabledChannelsForAgent(int $agentDefinitionId): array
    {
        return $this->channels;
    }

    public function stageMessage(int $channelId, string $connectorCode, ChannelMessage $message, ToolContext $ctx): array
    {
        $this->staged[] = ['channelId' => $channelId, 'connectorCode' => $connectorCode, 'message' => $message];

        return ['changeId' => 42, 'status' => 'pending'];
    }
}

class FakeOutboundMessageLogger implements AgentOutboundMessageLoggerInterface
{
    /** @var list<array{ctx: ToolContext, channel: string, recipient: ?string, status: string, error: ?string}> */
    public array $logged = [];

    public function log(ToolContext $ctx, string $channel, ?string $recipient, string $status, ?string $error = null): void
    {
        $this->logged[] = ['ctx' => $ctx, 'channel' => $channel, 'recipient' => $recipient, 'status' => $status, 'error' => $error];
    }
}

class SendToChannelToolTest extends TestCase
{
    private function context(?int $agentDefinitionId = 7): ToolContext
    {
        return new ToolContext(isAdmin: true, adminId: 3, conversationId: 9, agentDefinitionId: $agentDefinitionId, capabilities: ['channels.send'], agentRunId: 99);
    }

    public function testRequiresAnAgentContext(): void
    {
        $gateway = new FakeChannelGateway();
        $tool = new SendToChannelTool($gateway, new ChannelConnectorRegistry());

        $this->assertFalse($tool->isAllowed($this->context(null)));
        $result = $tool->execute(['message' => 'hello'], $this->context(null));

        $this->assertArrayHasKey('error', $result);
    }

    public function testRejectsEmptyMessage(): void
    {
        $tool = new SendToChannelTool(new FakeChannelGateway(), new ChannelConnectorRegistry());

        $result = $tool->execute(['message' => '   '], $this->context());

        $this->assertArrayHasKey('error', $result);
    }

    public function testErrorsWhenNoChannelIsConfigured(): void
    {
        $tool = new SendToChannelTool(new FakeChannelGateway([]), new ChannelConnectorRegistry());

        $result = $tool->execute(['message' => 'hello'], $this->context());

        $this->assertArrayHasKey('error', $result);
    }

    public function testDirectModeSendsImmediatelyThroughTheConnector(): void
    {
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $tool = new SendToChannelTool($gateway, $registry);

        $result = $tool->execute(['message' => 'Stock bas', 'subject' => 'Alerte'], $this->context());

        $this->assertCount(1, $connector->sent);
        $this->assertSame('Alerte', $connector->sent[0]->subject);
        $this->assertSame('Stock bas', $connector->sent[0]->body);
        $this->assertSame([], $gateway->staged);
        $this->assertSame('sent', $result['results'][0]['status']);
    }

    public function testDraftModeNeverCallsTheConnectorAndQueuesAStagedChange(): void
    {
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 2, 'connectorCode' => 'webhook', 'mode' => 'draft', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $tool = new SendToChannelTool($gateway, $registry);

        $result = $tool->execute(['message' => 'Rapport quotidien'], $this->context());

        $this->assertSame([], $connector->sent);
        $this->assertCount(1, $gateway->staged);
        $this->assertSame(2, $gateway->staged[0]['channelId']);
        $this->assertSame(42, $result['results'][0]['changeId']);
        $this->assertSame('pending', $result['results'][0]['status']);
    }

    public function testDirectSendFailureIsReportedPerChannelWithoutThrowing(): void
    {
        $connector = new FakeDirectConnector();
        $connector->throwOnSend = new ChannelException('Timeout');
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => []],
        ]);
        $tool = new SendToChannelTool($gateway, $registry);

        $result = $tool->execute(['message' => 'hello'], $this->context());

        $this->assertSame('Timeout', $result['results'][0]['error']);
    }

    public function testUnknownConnectorCodeIsReportedPerChannel(): void
    {
        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'telegram', 'mode' => 'direct', 'settings' => []],
        ]);
        $tool = new SendToChannelTool($gateway, new ChannelConnectorRegistry());

        $result = $tool->execute(['message' => 'hello'], $this->context());

        $this->assertArrayHasKey('error', $result['results'][0]);
    }

    public function testSendsToEachConfiguredChannelIndependently(): void
    {
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => ['url' => 'https://hooks.example/a']],
            ['id' => 2, 'connectorCode' => 'webhook', 'mode' => 'draft', 'settings' => ['url' => 'https://hooks.example/b']],
        ]);
        $tool = new SendToChannelTool($gateway, $registry);

        $result = $tool->execute(['message' => 'hello'], $this->context());

        $this->assertCount(2, $result['results']);
        $this->assertCount(1, $connector->sent);
        $this->assertCount(1, $gateway->staged);
    }

    public function testLogsOneOutboundMessageForADirectSend(): void
    {
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $outboundLogger = new FakeOutboundMessageLogger();
        $tool = new SendToChannelTool($gateway, $registry, $outboundLogger);

        $tool->execute(['message' => 'Stock bas'], $this->context());

        $this->assertCount(1, $outboundLogger->logged);
        $this->assertSame('webhook', $outboundLogger->logged[0]['channel']);
        $this->assertSame('https://hooks.example/x', $outboundLogger->logged[0]['recipient']);
        $this->assertSame('sent', $outboundLogger->logged[0]['status']);
        $this->assertNull($outboundLogger->logged[0]['error']);
    }

    public function testLogsAFailedOutboundMessageWhenTheDirectSendThrows(): void
    {
        $connector = new FakeDirectConnector();
        $connector->throwOnSend = new ChannelException('Timeout');
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => []],
        ]);
        $outboundLogger = new FakeOutboundMessageLogger();
        $tool = new SendToChannelTool($gateway, $registry, $outboundLogger);

        $tool->execute(['message' => 'hello'], $this->context());

        $this->assertSame('failed', $outboundLogger->logged[0]['status']);
        $this->assertSame('Timeout', $outboundLogger->logged[0]['error']);
    }

    public function testLogsAStagedOutboundMessageInDraftMode(): void
    {
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 2, 'connectorCode' => 'webhook', 'mode' => 'draft', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $outboundLogger = new FakeOutboundMessageLogger();
        $tool = new SendToChannelTool($gateway, $registry, $outboundLogger);

        $tool->execute(['message' => 'Rapport quotidien'], $this->context());

        $this->assertSame('staged', $outboundLogger->logged[0]['status']);
    }

    public function testLogsAFailedOutboundMessageForAnUnknownConnector(): void
    {
        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'telegram', 'mode' => 'direct', 'settings' => []],
        ]);
        $outboundLogger = new FakeOutboundMessageLogger();
        $tool = new SendToChannelTool($gateway, new ChannelConnectorRegistry(), $outboundLogger);

        $tool->execute(['message' => 'hello'], $this->context());

        $this->assertSame('failed', $outboundLogger->logged[0]['status']);
    }

    public function testDoesNotLogWhenNoOutboundMessageLoggerIsConfigured(): void
    {
        // Existing call sites (and the 2-arg constructor used throughout
        // this file) must keep compiling and behaving exactly as before.
        $connector = new FakeDirectConnector();
        $registry = new ChannelConnectorRegistry();
        $registry->register($connector);

        $gateway = new FakeChannelGateway([
            ['id' => 1, 'connectorCode' => 'webhook', 'mode' => 'direct', 'settings' => ['url' => 'https://hooks.example/x']],
        ]);
        $tool = new SendToChannelTool($gateway, $registry);

        $result = $tool->execute(['message' => 'hello'], $this->context());

        $this->assertSame('sent', $result['results'][0]['status']);
    }
}
