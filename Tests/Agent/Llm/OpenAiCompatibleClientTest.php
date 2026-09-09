<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Llm;

use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\LlmEvent;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Llm\LlmToolCall;
use CommerceAgents\Agent\Llm\OpenAiCompatibleClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class OpenAiCompatibleClientTest extends TestCase
{
    private function config(): LlmConfig
    {
        return new LlmConfig(
            provider: 'openai-compatible',
            model: 'mistral-large-latest',
            apiKey: 'sk-test-key',
            baseUrl: 'https://api.openrouter.ai',
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

        $client = new OpenAiCompatibleClient($http);
        $messages = [
            LlmMessage::user('Trouve une chaise'),
            LlmMessage::assistant('', [new LlmToolCall(id: 'call_1', name: 'search_products', arguments: ['query' => 'chaise'])]),
            LlmMessage::toolResult('call_1', ['count' => 3]),
        ];
        $toolSpecs = [[
            'name' => 'search_products',
            'description' => 'Search the catalog',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
        ]];

        foreach ($client->streamChat($messages, $toolSpecs, 'You are a shopping assistant.', $this->config()) as $event) {
            // drain the stream to trigger the request
        }

        $this->assertSame('https://api.openrouter.ai/v1/chat/completions', $capturedUrl);

        $headers = implode("\n", $capturedOptions['headers'] ?? []);
        $this->assertStringContainsString('Authorization: Bearer sk-test-key', $headers);

        $body = json_decode($capturedOptions['body'], true);
        $this->assertSame('mistral-large-latest', $body['model']);
        $this->assertTrue($body['stream']);

        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('search_products', $body['tools'][0]['function']['name']);
        $this->assertArrayHasKey('parameters', $body['tools'][0]['function']);

        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame('You are a shopping assistant.', $body['messages'][0]['content']);
        $this->assertSame('user', $body['messages'][1]['role']);

        $assistant = $body['messages'][2];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertSame('call_1', $assistant['tool_calls'][0]['id']);
        $this->assertSame('search_products', $assistant['tool_calls'][0]['function']['name']);
        $this->assertSame(['query' => 'chaise'], json_decode($assistant['tool_calls'][0]['function']['arguments'], true));

        $tool = $body['messages'][3];
        $this->assertSame('tool', $tool['role']);
        $this->assertSame('call_1', $tool['tool_call_id']);
    }

    public function testStreamParsing(): void
    {
        $chunks = [
            '{"object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","content":"Bonjour"},"finish_reason":null}]}',
            '{"object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"search_products","arguments":""}}]},"finish_reason":null}]}',
            '{"object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"query\":"}}]},"finish_reason":null}]}',
            '{"object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\"chaise\"}"}}]},"finish_reason":null}]}',
            '{"object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}',
        ];
        $sse = '';
        foreach ($chunks as $chunk) {
            $sse .= 'data: '.$chunk."\n\n";
        }
        $sse .= "data: [DONE]\n\n";

        $http = new MockHttpClient(new MockResponse($sse, ['response_headers' => ['content-type' => 'text/event-stream']]));
        $client = new OpenAiCompatibleClient($http);

        $events = iterator_to_array($client->streamChat([LlmMessage::user('Trouve une chaise')], [], '', $this->config()), false);

        $this->assertSame(LlmEvent::TEXT_DELTA, $events[0]->type);
        $this->assertSame('Bonjour', $events[0]->text);

        $this->assertSame(LlmEvent::TOOL_CALL, $events[1]->type);
        $this->assertSame('call_1', $events[1]->toolCall->id);
        $this->assertSame('search_products', $events[1]->toolCall->name);
        $this->assertSame(['query' => 'chaise'], $events[1]->toolCall->arguments);

        $this->assertSame(LlmEvent::TURN_END, $events[2]->type);
        $this->assertSame('tool_use', $events[2]->stopReason);
    }
}
