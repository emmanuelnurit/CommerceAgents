<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Service;

use CommerceAgents\Agent\Llm\LlmClientFactory;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Service\ConnectionTester;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectionTesterTest extends TestCase
{
    private function config(string $apiKey = 'sk-test'): LlmConfig
    {
        return new LlmConfig(provider: 'anthropic', model: 'claude-sonnet-5', apiKey: $apiKey);
    }

    private function tester(MockHttpClient $http): ConnectionTester
    {
        return new ConnectionTester(new LlmClientFactory($http));
    }

    public function testMissingApiKeyFailsWithoutCallingProvider(): void
    {
        $http = new MockHttpClient(function (): never {
            $this->fail('Provider must not be called without an API key');
        });

        $result = $this->tester($http)->test($this->config(apiKey: ''));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API key', $result['message']);
    }

    public function testSuccessfulResponse(): void
    {
        $sse = "event: content_block_delta\n"
            .'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"pong"}}'."\n\n"
            ."event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
        $http = new MockHttpClient(new MockResponse($sse, ['response_headers' => ['content-type' => 'text/event-stream']]));

        $result = $this->tester($http)->test($this->config());

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('claude-sonnet-5', $result['message']);
    }

    public function testProviderErrorIsReported(): void
    {
        $errorBody = '{"type":"error","error":{"message":"Your credit balance is too low"}}';
        $http = new MockHttpClient(new MockResponse($errorBody, [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $result = $this->tester($http)->test($this->config());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('credit balance is too low', $result['message']);
    }

    public function testTransportFailureIsReported(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'DNS resolution failed']));

        $result = $this->tester($http)->test($this->config());

        $this->assertFalse($result['success']);
        $this->assertNotSame('', $result['message']);
    }
}
