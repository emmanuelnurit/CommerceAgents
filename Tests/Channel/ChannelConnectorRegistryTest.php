<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Channel;

use CommerceAgents\Channel\ChannelConnectorInterface;
use CommerceAgents\Channel\ChannelConnectorRegistry;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\ConnectorTestResult;
use PHPUnit\Framework\TestCase;

/**
 * Stands in for a third-party module's connector: implementing the
 * interface is all it takes to be picked up by the registry.
 */
class FakeChannelConnector implements ChannelConnectorInterface
{
    /** @var list<array{message: ChannelMessage, settings: array}> */
    public array $sent = [];

    public function getCode(): string
    {
        return 'fake';
    }

    public function getLabel(): string
    {
        return 'Fake';
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
        $this->sent[] = ['message' => $message, 'settings' => $settings];
    }
}

class ChannelConnectorRegistryTest extends TestCase
{
    public function testThirdPartyConnectorIsDiscoverableByCode(): void
    {
        $registry = new ChannelConnectorRegistry();
        $connector = new FakeChannelConnector();
        $registry->register($connector);

        $this->assertTrue($registry->has('fake'));
        $this->assertSame($connector, $registry->get('fake'));
    }

    public function testUnknownCodeReturnsNull(): void
    {
        $registry = new ChannelConnectorRegistry();

        $this->assertFalse($registry->has('unknown'));
        $this->assertNull($registry->get('unknown'));
    }

    public function testDescribeAllExposesLabelAndSchema(): void
    {
        $registry = new ChannelConnectorRegistry();
        $registry->register(new FakeChannelConnector());

        $this->assertSame([
            ['code' => 'fake', 'label' => 'Fake', 'settingsSchema' => ['type' => 'object', 'properties' => []]],
        ], $registry->describeAll());
    }
}
