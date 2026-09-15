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
     * Two distinct failure modes here, not one:
     * - Propel/the config table is unreachable (e.g. pure unit tests without
     *   Propel booted): silently return null, the transport's own default
     *   "From" applies. This is a technical limitation of the test/runtime
     *   context, not a merchant-facing problem.
     * - The config read succeeds but `store_email` is empty/absent: this is
     *   a real merchant misconfiguration (no store e-mail set up) and must
     *   fail loudly instead of letting the message go out with no sender.
     */
    private function storeFromAddress(): ?Address
    {
        try {
            $storeEmail = ConfigQuery::getStoreEmail();
        } catch (\Throwable) {
            return null;
        }

        if (!\is_string($storeEmail) || $storeEmail === '') {
            throw new ChannelException('Le canal e-mail nécessite un expéditeur : renseignez l\'e-mail de la boutique (Configuration > Boutique) avant de l\'utiliser.');
        }

        return new Address($storeEmail);
    }
}
