<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Channel;

use CommerceAgents\Channel\Connector\MailChannelConnector;
use CommerceAgents\Channel\Connector\MattermostChannelConnector;
use CommerceAgents\Service\Channel\ChannelConnectorConfigService;
use CommerceAgents\Tests\Channel\Connector\FakeOutboundUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\MailerInterface;

class ChannelConnectorConfigServiceTest extends TestCase
{
    private function mattermostSchema(): array
    {
        return (new MattermostChannelConnector(new MockHttpClient(), new FakeOutboundUrlValidator()))->getSettingsSchema();
    }

    private function mailSchema(): array
    {
        return (new MailChannelConnector($this->createStub(MailerInterface::class)))->getSettingsSchema();
    }

    public function testSecretPropertiesFlagsOnlyUriFormattedFields(): void
    {
        $this->assertSame(['url'], ChannelConnectorConfigService::secretProperties($this->mattermostSchema()));
        $this->assertSame([], ChannelConnectorConfigService::secretProperties($this->mailSchema()));
    }

    public function testMergeKeepsTheStoredSecretWhenSubmittedBlank(): void
    {
        $current = ['url' => 'https://hooks.mattermost.example/existing'];

        $merged = ChannelConnectorConfigService::mergeSettings($current, ['url' => ''], $this->mattermostSchema());

        $this->assertSame('https://hooks.mattermost.example/existing', $merged['url']);
    }

    public function testMergeOverwritesTheSecretWhenANewValueIsSubmitted(): void
    {
        $current = ['url' => 'https://hooks.mattermost.example/existing'];

        $merged = ChannelConnectorConfigService::mergeSettings($current, ['url' => 'https://hooks.mattermost.example/new'], $this->mattermostSchema());

        $this->assertSame('https://hooks.mattermost.example/new', $merged['url']);
    }

    public function testMergeOverwritesANonSecretFieldEvenWhenBlank(): void
    {
        $current = ['to' => 'old@example.com'];

        $merged = ChannelConnectorConfigService::mergeSettings($current, ['to' => ''], $this->mailSchema());

        $this->assertSame('', $merged['to']);
    }

    public function testMergeFromScratchWithNoCurrentSettings(): void
    {
        $merged = ChannelConnectorConfigService::mergeSettings([], ['url' => 'https://hooks.mattermost.example/first'], $this->mattermostSchema());

        $this->assertSame(['url' => 'https://hooks.mattermost.example/first'], $merged);
    }
}
