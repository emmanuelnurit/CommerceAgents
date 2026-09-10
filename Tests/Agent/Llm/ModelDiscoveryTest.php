<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Llm;

use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\ModelDiscovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ModelDiscoveryTest extends TestCase
{
    public function testAnthropicListsAllPages(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = ['url' => $url, 'headers' => implode("\n", $options['headers'] ?? [])];
            if (str_contains($url, 'after_id=claude-sonnet-5')) {
                return new MockResponse('{"data":[{"id":"claude-haiku-4-5","display_name":"Claude Haiku 4.5","type":"model"}],"has_more":false}', ['response_headers' => ['content-type' => 'application/json']]);
            }

            return new MockResponse('{"data":[{"id":"claude-opus-5","display_name":"Claude Opus 5","type":"model"},{"id":"claude-sonnet-5","display_name":"Claude Sonnet 5","type":"model"}],"has_more":true,"last_id":"claude-sonnet-5"}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $models = (new ModelDiscovery($http))->list(new LlmConfig(provider: 'anthropic', model: '', apiKey: 'sk-ant-test'));

        $this->assertSame(['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5'], array_column($models, 'id'));
        $this->assertSame('Claude Haiku 4.5', $models[2]['name']);
        $this->assertCount(2, $requests);
        $this->assertStringStartsWith('https://api.anthropic.com/v1/models?limit=100', $requests[0]['url']);
        $this->assertStringContainsString('x-api-key: sk-ant-test', $requests[0]['headers']);
        $this->assertStringContainsString('anthropic-version: 2023-06-01', $requests[0]['headers']);
    }

    public function testMistralKeepsChatModelsOnlyAndSorts(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl) {
            $capturedUrl = $url;
            $this->assertStringContainsString('Authorization: Bearer m-key', implode("\n", $options['headers']));

            return new MockResponse('{"object":"list","data":[{"id":"mistral-small-2603","name":"mistral-small-2603","aliases":["mistral-small-latest","mistral-small-2603"],"capabilities":{"completion_chat":true}},{"id":"mistral-embed","capabilities":{"completion_chat":false}},{"id":"codestral-latest","capabilities":{"completion_chat":true}}]}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $models = (new ModelDiscovery($http))->list(new LlmConfig(provider: 'mistral', model: '', apiKey: 'm-key'));

        $this->assertSame('https://api.mistral.ai/v1/models', $capturedUrl);
        $this->assertSame(['codestral-latest', 'mistral-small-2603'], array_column($models, 'id'));
        $this->assertSame([], $models[0]['aliases']);
        $this->assertSame(['mistral-small-latest'], $models[1]['aliases'], 'own id is not an alias');
    }

    public function testOpenAiCompatibleUsesConfiguredBaseUrl(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse('{"object":"list","data":[{"id":"gpt-5-mini","object":"model"},{"id":"gpt-4.1","object":"model"}]}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $models = (new ModelDiscovery($http))->list(new LlmConfig(provider: 'openai-compatible', model: '', apiKey: 'sk-x', baseUrl: 'https://openrouter.ai/api/'));

        $this->assertSame('https://openrouter.ai/api/v1/models', $capturedUrl);
        $this->assertSame(['gpt-4.1', 'gpt-5-mini'], array_column($models, 'id'));
    }

    public function testProviderErrorIsThrownWithMessage(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"Invalid API key","type":"authentication_error"}}', [
            'http_code' => 401,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid API key');

        (new ModelDiscovery($http))->list(new LlmConfig(provider: 'anthropic', model: '', apiKey: 'bad'));
    }

    public function testMissingKeyDoesNotCallProvider(): void
    {
        $http = new MockHttpClient(function (): never {
            $this->fail('Provider must not be called without an API key');
        });

        $this->expectException(\InvalidArgumentException::class);

        (new ModelDiscovery($http))->list(new LlmConfig(provider: 'mistral', model: '', apiKey: ''));
    }
}
