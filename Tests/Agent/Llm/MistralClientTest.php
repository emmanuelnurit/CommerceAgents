<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Llm;

use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\LlmEvent;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Llm\LlmToolCall;
use CommerceAgents\Agent\Llm\MistralClient;
use CommerceAgents\Agent\Llm\MistralToolCallId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MistralClientTest extends TestCase
{
    private function config(?string $baseUrl = null): LlmConfig
    {
        return new LlmConfig(
            provider: 'mistral',
            model: 'mistral-large-latest',
            apiKey: 'mistral-test-key',
            baseUrl: $baseUrl,
        );
    }

    public function testRequestPayload(): void
    {
        $capturedUrl = null;
        $capturedOptions = null;

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions) {
            $this->assertSame('POST', $method);
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('', ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $client = new MistralClient($http);
        $messages = [
            LlmMessage::user('Trouve une chaise'),
            LlmMessage::assistant('', [new LlmToolCall(id: 'toolu_01A09q90qw90lq917835lq9', name: 'search_products', arguments: ['query' => 'chaise'])]),
            LlmMessage::toolResult('toolu_01A09q90qw90lq917835lq9', ['count' => 3]),
        ];
        $toolSpecs = [[
            'name' => 'search_products',
            'description' => 'Search the catalog',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
        ]];

        foreach ($client->streamChat($messages, $toolSpecs, 'You are a shopping assistant.', $this->config()) as $event) {
            // drain the stream to trigger the request
        }

        $this->assertSame('https://api.mistral.ai/v1/chat/completions', $capturedUrl);
        $this->assertSame(10.0, $capturedOptions['timeout'] ?? null, 'MYO-284 B2: an unresponsive base_url must not hang the worker forever');

        $headers = implode("\n", $capturedOptions['headers'] ?? []);
        $this->assertStringContainsString('Authorization: Bearer mistral-test-key', $headers);

        $body = json_decode($capturedOptions['body'], true);
        $this->assertSame('mistral-large-latest', $body['model']);
        $this->assertTrue($body['stream']);
        $this->assertArrayNotHasKey('stream_options', $body, 'Mistral rejects unknown request fields');

        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('search_products', $body['tools'][0]['function']['name']);

        $this->assertSame('system', $body['messages'][0]['role']);

        $assistantToolCallId = $body['messages'][2]['tool_calls'][0]['id'];
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{9}$/', $assistantToolCallId, 'Mistral requires 9 alphanumeric characters');
        $this->assertSame($assistantToolCallId, $body['messages'][3]['tool_call_id'], 'tool result must reference the remapped id');
        $this->assertSame('tool', $body['messages'][3]['role']);
    }

    public function testCustomBaseUrl(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(static function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse('', ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        foreach ((new MistralClient($http))->streamChat([LlmMessage::user('ping')], [], '', $this->config('https://mistral.example.test/')) as $event) {
        }

        $this->assertSame('https://mistral.example.test/v1/chat/completions', $capturedUrl);
    }

    public function testHttpErrorYieldsErrorEventInsteadOfThrowing(): void
    {
        $errorBody = '{"object":"error","message":"Unauthorized","type":"invalid_request_error","code":"1000"}';
        $http = new MockHttpClient(new MockResponse($errorBody, [
            'http_code' => 401,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $events = iterator_to_array((new MistralClient($http))->streamChat([LlmMessage::user('ping')], [], '', $this->config()), false);

        $this->assertCount(1, $events);
        $this->assertSame(LlmEvent::ERROR, $events[0]->type);
        $this->assertStringContainsString('Unauthorized', $events[0]->text);
    }

    public function testStreamParsingWithUsage(): void
    {
        $chunks = [
            '{"id":"c1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","content":"Bonjour"},"finish_reason":null}]}',
            '{"id":"c1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"TX92Jm8Zi","function":{"name":"search_products","arguments":"{\"query\": \"chaise\"}"}}]},"finish_reason":"tool_calls"}],"usage":{"prompt_tokens":120,"completion_tokens":18,"total_tokens":138}}',
        ];
        $sse = '';
        foreach ($chunks as $chunk) {
            $sse .= 'data: '.$chunk."\n\n";
        }
        $sse .= "data: [DONE]\n\n";

        $http = new MockHttpClient(new MockResponse($sse, ['response_headers' => ['content-type' => 'text/event-stream']]));

        $events = iterator_to_array((new MistralClient($http))->streamChat([LlmMessage::user('Trouve une chaise')], [], '', $this->config()), false);

        $this->assertSame(LlmEvent::TEXT_DELTA, $events[0]->type);
        $this->assertSame('Bonjour', $events[0]->text);

        $this->assertSame(LlmEvent::TOOL_CALL, $events[1]->type);
        $this->assertSame('TX92Jm8Zi', $events[1]->toolCall->id);
        $this->assertSame(['query' => 'chaise'], $events[1]->toolCall->arguments);

        $this->assertSame(LlmEvent::TURN_END, $events[2]->type);
        $this->assertSame('tool_use', $events[2]->stopReason);
        $this->assertSame(120, $events[2]->inputTokens);
        $this->assertSame(18, $events[2]->outputTokens);
    }

    public function testToolCallIdNormalization(): void
    {
        $this->assertSame('TX92Jm8Zi', MistralToolCallId::normalize('TX92Jm8Zi'), 'native Mistral ids are kept');

        $remapped = MistralToolCallId::normalize('toolu_01A09q90qw90lq917835lq9');
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{9}$/', $remapped);
        $this->assertSame($remapped, MistralToolCallId::normalize('toolu_01A09q90qw90lq917835lq9'), 'deterministic');
        $this->assertNotSame($remapped, MistralToolCallId::normalize('call_abc123'));
    }
}
