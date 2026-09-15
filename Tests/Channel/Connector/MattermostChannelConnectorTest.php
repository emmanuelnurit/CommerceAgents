<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Channel\Connector;

use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\Connector\MattermostChannelConnector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MattermostChannelConnectorTest extends TestCase
{
    public function testCodeAndLabelAreDistinctFromSlack(): void
    {
        $connector = new MattermostChannelConnector(new MockHttpClient(), new FakeOutboundUrlValidator());

        $this->assertSame('mattermost', $connector->getCode());
        $this->assertSame('Mattermost', $connector->getLabel());
    }

    public function testSendPostsJsonTextPayloadToTheConfiguredUrl(): void
    {
        $capturedUrl = null;
        $capturedBody = null;

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody) {
            $this->assertSame('POST', $method);
            $capturedUrl = $url;
            $capturedBody = $options['body'] ?? null;

            return new MockResponse('ok', ['http_code' => 200]);
        });

        $connector = new MattermostChannelConnector($http, new FakeOutboundUrlValidator());
        $connector->send(new ChannelMessage('Rupture de stock', 'Le produit X est épuisé.'), ['url' => 'https://hooks.mattermost.example/local-fake']);

        $this->assertSame('https://hooks.mattermost.example/local-fake', $capturedUrl);
        $decoded = json_decode((string) $capturedBody, true);
        $this->assertStringContainsString('Rupture de stock', $decoded['text']);
        $this->assertStringContainsString('Le produit X est épuisé.', $decoded['text']);
    }

    public function testSendRejectsInvalidUrl(): void
    {
        $connector = new MattermostChannelConnector(new MockHttpClient(), new FakeOutboundUrlValidator());

        $this->expectException(ChannelException::class);
        $connector->send(new ChannelMessage(null, 'body'), ['url' => 'not-a-url']);
    }

    public function testSendRejectsUrlBlockedBySsrfGuard(): void
    {
        $connector = new MattermostChannelConnector(new MockHttpClient(), new FakeOutboundUrlValidator(false));

        $this->expectException(ChannelException::class);
        $connector->send(new ChannelMessage(null, 'body'), ['url' => 'https://169.254.169.254/latest/meta-data']);
    }

    public function testSendThrowsOnNonSuccessStatus(): void
    {
        $http = new MockHttpClient(static fn () => new MockResponse('nope', ['http_code' => 500]));
        $connector = new MattermostChannelConnector($http, new FakeOutboundUrlValidator());

        $this->expectException(ChannelException::class);
        $connector->send(new ChannelMessage(null, 'body'), ['url' => 'https://hooks.mattermost.example/local-fake']);
    }

    public function testTestAgainstLocalFakeEndpointReportsSuccess(): void
    {
        $http = new MockHttpClient(static fn () => new MockResponse('ok', ['http_code' => 200]));
        $connector = new MattermostChannelConnector($http, new FakeOutboundUrlValidator());

        $result = $connector->test(['url' => 'https://hooks.mattermost.example/local-fake']);

        $this->assertTrue($result->success);
    }

    public function testTestAgainstLocalFakeEndpointReportsFailure(): void
    {
        $http = new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 404]));
        $connector = new MattermostChannelConnector($http, new FakeOutboundUrlValidator());

        $result = $connector->test(['url' => 'https://hooks.mattermost.example/local-fake']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('404', $result->message);
    }
}
