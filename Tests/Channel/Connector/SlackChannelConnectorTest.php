<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Channel\Connector;

use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\Connector\SlackChannelConnector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SlackChannelConnectorTest extends TestCase
{
    public function testCodeAndLabelAreDistinctFromMattermost(): void
    {
        $connector = new SlackChannelConnector(new MockHttpClient(), new FakeOutboundUrlValidator());

        $this->assertSame('slack', $connector->getCode());
        $this->assertSame('Slack', $connector->getLabel());
    }

    public function testSendWithoutSubjectSendsBodyOnly(): void
    {
        $http = new MockHttpClient(static fn () => new MockResponse('ok', ['http_code' => 200]));
        $connector = new SlackChannelConnector($http, new FakeOutboundUrlValidator());

        $connector->send(new ChannelMessage(null, 'Ping'), ['url' => 'https://hooks.slack.example/local-fake']);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function testTestAgainstLocalFakeEndpointReportsSuccess(): void
    {
        $http = new MockHttpClient(static fn () => new MockResponse('ok', ['http_code' => 200]));
        $connector = new SlackChannelConnector($http, new FakeOutboundUrlValidator());

        $result = $connector->test(['url' => 'https://hooks.slack.example/local-fake']);

        $this->assertTrue($result->success);
    }

    public function testGetSettingsSchemaMentionsSlackInDescription(): void
    {
        $connector = new SlackChannelConnector(new MockHttpClient(), new FakeOutboundUrlValidator());

        $schema = $connector->getSettingsSchema();

        $this->assertSame(['url'], $schema['required']);
        $this->assertStringContainsString('Slack', $schema['properties']['url']['description']);
    }
}
