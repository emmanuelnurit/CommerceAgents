<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent\Llm;

use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Llm\LlmToolCall;
use PHPUnit\Framework\TestCase;

class LlmMessageTest extends TestCase
{
    public function testUserMessage(): void
    {
        $m = LlmMessage::user('Bonjour');
        $this->assertSame('user', $m->role);
        $this->assertSame('Bonjour', $m->content);
        $this->assertSame([], $m->toolCalls);
    }

    public function testAssistantMessageWithToolCalls(): void
    {
        $call = new LlmToolCall(id: 'tc_1', name: 'search_products', arguments: ['query' => 'chaise']);
        $m = LlmMessage::assistant('', [$call]);
        $this->assertSame('search_products', $m->toolCalls[0]->name);
    }

    public function testToolResultMessage(): void
    {
        $m = LlmMessage::toolResult('tc_1', ['count' => 3]);
        $this->assertSame('tool', $m->role);
        $this->assertSame('tc_1', $m->toolCallId);
    }
}
