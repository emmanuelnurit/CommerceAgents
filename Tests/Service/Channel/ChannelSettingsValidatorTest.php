<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service\Channel;

use CommerceAgents\Channel\Connector\MailChannelConnector;
use CommerceAgents\Channel\Connector\MattermostChannelConnector;
use CommerceAgents\Service\Channel\ChannelSettingsValidator;
use CommerceAgents\Tests\Channel\Connector\FakeOutboundUrlValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Thelia\Core\Translation\Translator;

class ChannelSettingsValidatorTest extends TestCase
{
    private function mattermostSchema(): array
    {
        return (new MattermostChannelConnector(new MockHttpClient(), new FakeOutboundUrlValidator(), new Translator(new RequestStack())))->getSettingsSchema();
    }

    private function mailSchema(): array
    {
        return (new MailChannelConnector($this->createStub(MailerInterface::class), new Translator(new RequestStack())))->getSettingsSchema();
    }

    public function testRequiredFieldMissingIsReported(): void
    {
        $validator = new ChannelSettingsValidator(new FakeOutboundUrlValidator());

        $errors = $validator->validate($this->mattermostSchema(), []);

        $this->assertNotEmpty($errors);
    }

    public function testInvalidEmailIsReported(): void
    {
        $validator = new ChannelSettingsValidator(new FakeOutboundUrlValidator());

        $errors = $validator->validate($this->mailSchema(), ['to' => 'not-an-email']);

        $this->assertNotEmpty($errors);
    }

    public function testValidEmailPassesValidation(): void
    {
        $validator = new ChannelSettingsValidator(new FakeOutboundUrlValidator());

        $this->assertSame([], $validator->validate($this->mailSchema(), ['to' => 'shop@example.com']));
    }

    public function testMalformedUrlIsReported(): void
    {
        $validator = new ChannelSettingsValidator(new FakeOutboundUrlValidator());

        $errors = $validator->validate($this->mattermostSchema(), ['url' => 'not-a-url']);

        $this->assertNotEmpty($errors);
    }

    public function testUrlRejectedBySsrfGuardIsReportedWithAClearMessage(): void
    {
        $validator = new ChannelSettingsValidator(new FakeOutboundUrlValidator(false));

        $errors = $validator->validate($this->mattermostSchema(), ['url' => 'https://169.254.169.254/latest/meta-data']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('interne', implode(' ', $errors));
    }

    public function testValidPublicUrlPassesValidation(): void
    {
        $validator = new ChannelSettingsValidator(new FakeOutboundUrlValidator(true));

        $this->assertSame([], $validator->validate($this->mattermostSchema(), ['url' => 'https://hooks.mattermost.example/valid']));
    }
}
