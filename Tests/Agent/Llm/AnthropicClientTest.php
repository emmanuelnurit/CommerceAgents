<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Llm;

use CommerceAgents\Agent\Llm\AnthropicClient;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\LlmEvent;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Llm\LlmToolCall;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AnthropicClientTest extends TestCase
{
    private function config(): LlmConfig
    {
        return new LlmConfig(
            provider: 'anthropic',
            model: 'claude-sonnet-5',
            apiKey: 'sk-test-key',
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

        $client = new AnthropicClient($http);
        $messages = [
            LlmMessage::user('Trouve une chaise'),
            LlmMessage::assistant('', [new LlmToolCall(id: 'tc_1', name: 'search_products', arguments: ['query' => 'chaise'])]),
            LlmMessage::toolResult('tc_1', ['count' => 3]),
        ];
        $toolSpecs = [[
            'name' => 'search_products',
            'description' => 'Search the catalog',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
        ]];

        foreach ($client->streamChat($messages, $toolSpecs, 'You are a shopping assistant.', $this->config()) as $event) {
            // drain the stream to trigger the request
        }

        $this->assertSame('https://api.anthropic.com/v1/messages', $capturedUrl);
        $this->assertSame(10.0, $capturedOptions['timeout'] ?? null, 'MYO-284 B2: an unresponsive base_url must not hang the worker forever');

        $headers = implode("\n", $capturedOptions['headers'] ?? []);
        $this->assertStringContainsString('x-api-key: sk-test-key', $headers);
        $this->assertStringContainsString('anthropic-version: 2023-06-01', $headers);

        $body = json_decode($capturedOptions['body'], true);
        $this->assertSame('claude-sonnet-5', $body['model']);
        $this->assertSame('You are a shopping assistant.', $body['system']);
        $this->assertTrue($body['stream']);
        $this->assertSame('search_products', $body['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $body['tools'][0]);

        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertSame('assistant', $body['messages'][1]['role']);
        $this->assertSame('tool_use', $body['messages'][1]['content'][0]['type']);
        $this->assertSame(['query' => 'chaise'], $body['messages'][1]['content'][0]['input']);
        $this->assertSame('user', $body['messages'][2]['role']);
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('tc_1', $body['messages'][2]['content'][0]['tool_use_id']);
    }

    public function testHttpErrorYieldsErrorEventInsteadOfThrowing(): void
    {
        $errorBody = '{"type":"error","error":{"type":"invalid_request_error","message":"Your credit balance is too low"}}';
        $http = new MockHttpClient(new MockResponse($errorBody, [
            'http_code' => 400,
            'response_headers' => ['content-type' => 'application/json'],
        ]));
        $client = new AnthropicClient($http);

        $events = iterator_to_array($client->streamChat([LlmMessage::user('ping')], [], '', $this->config()), false);

        $this->assertCount(1, $events);
        $this->assertSame(LlmEvent::ERROR, $events[0]->type);
        $this->assertStringContainsString('credit balance is too low', $events[0]->text);
    }

    public function testStreamParsing(): void
    {
        $sse = implode('', [
            "event: message_start\n",
            'data: {"type":"message_start","message":{"id":"msg_1","role":"assistant","usage":{"input_tokens":310,"cache_creation_input_tokens":0,"cache_read_input_tokens":40,"output_tokens":1}}}'."\n\n",
            "event: content_block_start\n",
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}'."\n\n",
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Bonjour"}}'."\n\n",
            "event: content_block_stop\n",
            'data: {"type":"content_block_stop","index":0}'."\n\n",
            "event: content_block_start\n",
            'data: {"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"tc_1","name":"search_products"}}'."\n\n",
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"query\":"}}'."\n\n",
            "event: content_block_delta\n",
            'data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"\"chaise\"}"}}'."\n\n",
            "event: content_block_stop\n",
            'data: {"type":"content_block_stop","index":1}'."\n\n",
            "event: message_delta\n",
            'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":57}}'."\n\n",
            "event: message_stop\n",
            'data: {"type":"message_stop"}'."\n\n",
        ]);

        $http = new MockHttpClient(new MockResponse($sse, ['response_headers' => ['content-type' => 'text/event-stream']]));
        $client = new AnthropicClient($http);

        $events = iterator_to_array($client->streamChat([LlmMessage::user('Trouve une chaise')], [], '', $this->config()), false);

        $this->assertSame(LlmEvent::TEXT_DELTA, $events[0]->type);
        $this->assertSame('Bonjour', $events[0]->text);

        $this->assertSame(LlmEvent::TOOL_CALL, $events[1]->type);
        $this->assertSame('tc_1', $events[1]->toolCall->id);
        $this->assertSame('search_products', $events[1]->toolCall->name);
        $this->assertSame(['query' => 'chaise'], $events[1]->toolCall->arguments);

        $this->assertSame(LlmEvent::TURN_END, $events[2]->type);
        $this->assertSame('tool_use', $events[2]->stopReason);
        $this->assertSame(350, $events[2]->inputTokens, 'input + cache read + cache creation');
        $this->assertSame(57, $events[2]->outputTokens);
    }
}
