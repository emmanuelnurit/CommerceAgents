<?php

declare(strict_types=1);

namespace CommerceAgents\Channel\Connector;

use CommerceAgents\Channel\ChannelConnectorInterface;
use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\ConnectorTestResult;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Thelia\Model\ConfigQuery;

final readonly class MailChannelConnector implements ChannelConnectorInterface
{
    public function __construct(
        private MailerInterface $mailer,
    ) {
    }

    public function getCode(): string
    {
        return 'mail';
    }

    public function getLabel(): string
    {
        return 'E-mail';
    }

    public function getSettingsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'Adresse e-mail destinataire',
                ],
            ],
            'required' => ['to'],
        ];
    }

    public function test(array $settings): ConnectorTestResult
    {
        try {
            $this->send(
                new ChannelMessage('Test de connexion CommerceAgents', 'Ce message confirme que le canal e-mail est correctement configuré.'),
                $settings,
            );
        } catch (ChannelException $exception) {
            return ConnectorTestResult::failure($exception->getMessage());
        }

        return ConnectorTestResult::success(\sprintf('E-mail de test envoyé à %s', $settings['to']));
    }

    public function send(ChannelMessage $message, array $settings): void
    {
        $to = trim((string) ($settings['to'] ?? ''));
        if ($to === '' || filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            throw new ChannelException('Le paramètre "to" doit être une adresse e-mail valide');
        }

        $email = (new Email())
            ->to(new Address($to))
            ->subject($message->subject ?? 'Notification CommerceAgents')
            ->text($message->body);

        if (($from = $this->storeFromAddress()) !== null) {
            $email->from($from);
        }

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw new ChannelException('Envoi e-mail impossible : '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Swallows any Propel/DB failure: the connector still exercises the mail
     * transport, only the "From" header is left to the transport's default
     * when the store configuration is unavailable (e.g. in unit tests).
     */
    private function storeFromAddress(): ?Address
    {
        try {
            $storeEmail = ConfigQuery::getStoreEmail();
        } catch (\Throwable) {
            return null;
        }

        return \is_string($storeEmail) && $storeEmail !== '' ? new Address($storeEmail) : null;
    }
}
