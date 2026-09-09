<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Llm;

use CommerceAgents\Agent\Llm\HistorySanitizer;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Llm\LlmToolCall;
use PHPUnit\Framework\TestCase;

class HistorySanitizerTest extends TestCase
{
    public function testKeepsCompleteToolExchange(): void
    {
        $messages = [
            LlmMessage::user('search a chair'),
            LlmMessage::assistant('', [new LlmToolCall(id: 'tc_1', name: 'search_products', arguments: [])]),
            LlmMessage::toolResult('tc_1', ['count' => 2]),
            LlmMessage::assistant('Here are two chairs'),
        ];

        $this->assertSame($messages, HistorySanitizer::sanitize($messages));
    }

    public function testDropsDanglingToolUseWithoutResult(): void
    {
        $messages = [
            LlmMessage::user('search a chair'),
            LlmMessage::assistant('', [new LlmToolCall(id: 'tc_1', name: 'search_products', arguments: [])]),
            LlmMessage::user('ping'),
        ];

        $sanitized = HistorySanitizer::sanitize($messages);

        $this->assertCount(2, $sanitized);
        $this->assertSame('user', $sanitized[0]->role);
        $this->assertSame('user', $sanitized[1]->role);
    }

    public function testKeepsAssistantTextButDropsUnresolvedToolCall(): void
    {
        $messages = [
            LlmMessage::user('hi'),
            LlmMessage::assistant('Let me check', [new LlmToolCall(id: 'tc_1', name: 'search_products', arguments: [])]),
        ];

        $sanitized = HistorySanitizer::sanitize($messages);

        $this->assertCount(2, $sanitized);
        $this->assertSame('assistant', $sanitized[1]->role);
        $this->assertSame('Let me check', $sanitized[1]->content);
        $this->assertSame([], $sanitized[1]->toolCalls);
    }

    public function testDropsOrphanToolResult(): void
    {
        $messages = [
            LlmMessage::user('hi'),
            LlmMessage::toolResult('tc_unknown', ['count' => 0]),
        ];

        $sanitized = HistorySanitizer::sanitize($messages);

        $this->assertCount(1, $sanitized);
        $this->assertSame('user', $sanitized[0]->role);
    }
}
