<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Channel\Connector;

use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\Connector\MailChannelConnector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;

class FakeMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public ?\Throwable $throwOnSend = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }
        $this->sent[] = $message;
    }
}

class MailChannelConnectorTest extends TestCase
{
    public function testSendBuildsEmailWithSubjectAndBody(): void
    {
        $mailer = new FakeMailer();
        $connector = new MailChannelConnector($mailer);

        $connector->send(new ChannelMessage('Alerte stock', 'Le produit X est en rupture.'), ['to' => 'merchant@example.com']);

        $this->assertCount(1, $mailer->sent);
        /** @var Email $email */
        $email = $mailer->sent[0];
        $this->assertSame('Alerte stock', $email->getSubject());
        $this->assertSame('Le produit X est en rupture.', $email->getTextBody());
        $this->assertSame('merchant@example.com', $email->getTo()[0]->getAddress());
    }

    public function testSendRejectsInvalidRecipient(): void
    {
        $connector = new MailChannelConnector(new FakeMailer());

        $this->expectException(\CommerceAgents\Channel\ChannelException::class);
        $connector->send(new ChannelMessage(null, 'body'), ['to' => 'not-an-email']);
    }

    public function testTestSucceedsWhenMailerAccepts(): void
    {
        $connector = new MailChannelConnector(new FakeMailer());

        $result = $connector->test(['to' => 'merchant@example.com']);

        $this->assertTrue($result->success);
    }

    public function testTestFailsWhenTransportThrows(): void
    {
        $mailer = new FakeMailer();
        $mailer->throwOnSend = new TransportException('Connection refused');
        $connector = new MailChannelConnector($mailer);

        $result = $connector->test(['to' => 'merchant@example.com']);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection refused', $result->message);
    }
}
