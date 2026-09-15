<?php

declare(strict_types=1);

namespace CommerceAgents\Channel\Connector;

use CommerceAgents\Channel\ChannelConnectorInterface;
use CommerceAgents\Channel\ChannelException;
use CommerceAgents\Channel\ChannelMessage;
use CommerceAgents\Channel\ConnectorTestResult;
use CommerceAgents\Service\Security\OutboundUrlValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generic incoming-webhook connector: both Mattermost and Slack accept a
 * `{"text": "..."}` JSON body on their incoming webhook URL, so one connector
 * covers both without knowing which of the two it is talking to (plan
 * MYO-226 §3.6 — rich per-provider integrations are V2).
 */
final readonly class WebhookChannelConnector implements ChannelConnectorInterface
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
        private OutboundUrlValidatorInterface $urlValidator,
    ) {
    }

    public function getCode(): string
    {
        return 'webhook';
    }

    public function getLabel(): string
    {
        return 'Webhook (Mattermost / Slack)';
    }

    public function getSettingsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'URL du webhook entrant Mattermost ou Slack',
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
