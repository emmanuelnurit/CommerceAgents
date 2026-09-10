<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lists the chat models a provider account can use, through each provider's
 * models endpoint. Prices are not part of those APIs: they come from the
 * bundled catalog or from the merchant.
 */
final readonly class ModelDiscovery
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_BASE_URLS = [
        'anthropic' => 'https://api.anthropic.com',
        'mistral' => 'https://api.mistral.ai',
        'openai-compatible' => 'https://api.openai.com',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, aliases: list<string>}> aliases are other ids naming the same model (Mistral "-latest" ids)
     */
    public function list(LlmConfig $config): array
    {
        if ($config->apiKey === '') {
            throw new \InvalidArgumentException('No API key configured for this provider');
        }
        if (!isset(self::DEFAULT_BASE_URLS[$config->provider])) {
            throw new \InvalidArgumentException(sprintf('Unknown LLM provider "%s"', $config->provider));
        }

        $baseUrl = rtrim($config->baseUrl ?: self::DEFAULT_BASE_URLS[$config->provider], '/');

        return match ($config->provider) {
            'anthropic' => $this->listAnthropic($baseUrl, $config->apiKey),
            default => $this->listOpenAiStyle($baseUrl, $config->apiKey, $config->provider === 'mistral'),
        };
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function listAnthropic(string $baseUrl, string $apiKey): array
    {
        $models = [];
        $afterId = null;

        for ($page = 0; $page < 10; ++$page) {
            $query = ['limit' => 100];
            if ($afterId !== null) {
                $query['after_id'] = $afterId;
            }

            $payload = $this->fetch($baseUrl.'/v1/models', $query, [
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
            ]);

            foreach ($payload['data'] ?? [] as $model) {
                if (!isset($model['id'])) {
                    continue;
                }
                $models[] = ['id' => (string) $model['id'], 'name' => (string) ($model['display_name'] ?? $model['id']), 'aliases' => []];
            }

            if (($payload['has_more'] ?? false) !== true || !isset($payload['last_id'])) {
                break;
            }
            $afterId = (string) $payload['last_id'];
        }

        return $models;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function listOpenAiStyle(string $baseUrl, string $apiKey, bool $chatOnly): array
    {
        $payload = $this->fetch($baseUrl.'/v1/models', [], ['Authorization' => 'Bearer '.$apiKey]);

        $models = [];
        foreach ($payload['data'] ?? [] as $model) {
            if (!isset($model['id'])) {
                continue;
            }
            if ($chatOnly && isset($model['capabilities']) && ($model['capabilities']['completion_chat'] ?? true) === false) {
                continue;
            }
            $aliases = array_values(array_filter(array_map('strval', (array) ($model['aliases'] ?? [])), static fn (string $alias): bool => $alias !== '' && $alias !== $model['id']));
            $models[] = ['id' => (string) $model['id'], 'name' => (string) ($model['name'] ?? $model['id']), 'aliases' => $aliases];
        }

        usort($models, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return $models;
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, string>     $headers
     */
    private function fetch(string $url, array $query, array $headers): array
    {
        $response = $this->httpClient->request('GET', $url, ['query' => $query, 'headers' => $headers]);

        if ($response->getStatusCode() >= 400) {
            $body = json_decode($response->getContent(false), true);
            $message = $body['error']['message'] ?? $body['message'] ?? sprintf('Provider returned HTTP %d', $response->getStatusCode());
            throw new \RuntimeException(\is_string($message) ? $message : json_encode($message, \JSON_THROW_ON_ERROR));
        }

        $payload = json_decode($response->getContent(false), true);

        return \is_array($payload) ? $payload : [];
    }
}
