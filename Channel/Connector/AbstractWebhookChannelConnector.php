<?php

declare(strict_types=1);

namespace CommerceAgents\Channel\Connector;

use CommerceAgents\Channel\ChannelConnectorInterface;
use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\ConnectorTestResult;
use CommerceAgents\CommerceAgents;
use CommerceAgents\Service\Security\OutboundUrlValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Thelia\Core\Translation\Translator;

/**
 * Incoming-webhook send/test logic shared by every provider that accepts a
 * `{"text": "..."}` JSON body on an incoming webhook URL (Mattermost, Slack —
 * MYO-300 splits what used to be one generic 'webhook' connector into two
 * named, registry-visible connectors so the BO screen doesn't force merchants
 * to know they share a wire format; only getCode()/getLabel() differ).
 */
abstract readonly class AbstractWebhookChannelConnector implements ChannelConnectorInterface
{
    protected const TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
        private OutboundUrlValidatorInterface $urlValidator,
        private Translator $translator,
    ) {
    }

    public function getSettingsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => $this->translator->trans(
                        'Incoming webhook URL %label%',
                        ['%label%' => $this->getLabel()],
                        CommerceAgents::DOMAIN_NAME,
                    ),
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function test(array $settings): ConnectorTestResult
    {
        try {
            $this->send(new ChannelMessage(null, 'Test de connexion CommerceAgents ✅'), $settings);
        } catch (ChannelException $exception) {
            return ConnectorTestResult::failure($exception->getMessage());
        }

        return ConnectorTestResult::success('Webhook de test envoyé avec succès');
    }

    public function send(ChannelMessage $message, array $settings): void
    {
        $url = trim((string) ($settings['url'] ?? ''));
        if ($url === '' || filter_var($url, \FILTER_VALIDATE_URL) === false) {
            throw new ChannelException('Le paramètre "url" doit être une URL valide');
        }
        // MYO-276: a webhook URL is an SSRF vector (internal services, cloud
        // metadata endpoint) -- https only, no private/loopback/link-local
        // target.
        if (!$this->urlValidator->isAllowed($url)) {
            throw new ChannelException('Le paramètre "url" doit être une URL HTTPS publique (pas d\'adresse privée/locale)');
        }

        $text = $message->subject !== null
            ? \sprintf('**%s**\n\n%s', $message->subject, $message->body)
            : $message->body;

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => ['text' => $text],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            throw new ChannelException('Envoi webhook impossible : '.$exception->getMessage(), previous: $exception);
        }

        if ($status < 200 || $status >= 300) {
            throw new ChannelException(\sprintf('Le webhook a répondu avec le statut HTTP %d', $status));
        }
    }
}
