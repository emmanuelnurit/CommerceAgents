<?php

declare(strict_types=1);

namespace CommerceAgents\Tests\Agent;

use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmClientInterface;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\LlmEvent;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Llm\LlmToolCall;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolInterface;
use CommerceAgents\Agent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ScriptedLlmClient implements LlmClientInterface
{
    public int $callCount = 0;

    /** @var array<int, LlmMessage[]> messages received at each call */
    public array $receivedMessages = [];

    /** @param array<int, LlmEvent[]> $scripts one event sequence per call */
    public function __construct(private readonly array $scripts)
    {
    }

    public function streamChat(array $messages, array $toolSpecs, string $system, LlmConfig $config): \Generator
    {
        $this->receivedMessages[] = $messages;
        $script = $this->scripts[$this->callCount] ?? $this->scripts[array_key_last($this->scripts)];
        ++$this->callCount;

        yield from $script;
    }
}

class RecordingEchoTool implements ToolInterface
{
    public int $executions = 0;

    public function __construct(private readonly bool $failing = false)
    {
    }

    public function getName(): string
    {
        return 'echo';
    }

    public function getDescription(): string
    {
        return 'Echoes input';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => []];
    }

    public function isAllowed(ToolContext $ctx): bool
    {
        return true;
    }

    public function execute(array $args, ToolContext $ctx): array
    {
        ++$this->executions;
        if ($this->failing) {
            throw new ToolException('boom');
        }

        return ['echo' => $args['text'] ?? ''];
    }
}

class AgentRuntimeTest extends TestCase
{
    private function config(): LlmConfig
    {
        return new LlmConfig(provider: 'anthropic', model: 'claude-sonnet-5', apiKey: 'sk-test');
    }

    private function runTurn(ScriptedLlmClient $client, ToolRegistry $registry): array
    {
        $runtime = new AgentRuntime($client, $registry);

        return iterator_to_array(
            $runtime->runTurn([LlmMessage::user('Salut')], 'system prompt', new ToolContext(), $this->config()),
            false,
        );
    }

    public function testSimpleTextTurn(): void
    {
        $client = new ScriptedLlmClient([
            [LlmEvent::textDelta('Bon'), LlmEvent::textDelta('jour'), LlmEvent::turnEnd('end_turn')],
        ]);

        $events = $this->runTurn($client, new ToolRegistry());

        $this->assertSame(['text_delta', 'text_delta', 'done'], array_column($events, 'type'));
        $this->assertSame('Bon', $events[0]->payload['text']);
        $this->assertSame('jour', $events[1]->payload['text']);
        $this->assertSame(1, $client->callCount);
    }

    public function testToolCallTurn(): void
    {
        $client = new ScriptedLlmClient([
            [LlmEvent::toolCall(new LlmToolCall(id: 'tc_1', name: 'echo', arguments: ['text' => 'hi'])), LlmEvent::turnEnd('tool_use')],
            [LlmEvent::textDelta('fait'), LlmEvent::turnEnd('end_turn')],
        ]);
        $tool = new RecordingEchoTool();
        $registry = new ToolRegistry();
        $registry->register($tool);

        $events = $this->runTurn($client, $registry);

        $this->assertSame(['tool_call', 'tool_result', 'text_delta', 'done'], array_column($events, 'type'));
        $this->assertSame(1, $tool->executions);
        $this->assertSame(2, $client->callCount);

        $secondCallMessages = $client->receivedMessages[1];
        $lastMessage = end($secondCallMessages);
        $this->assertSame('tool', $lastMessage->role);
        $this->assertSame('tc_1', $lastMessage->toolCallId);
        $this->assertSame(['echo' => 'hi'], $lastMessage->toolResult);
    }

    public function testToolErrorIsSentBackToLlm(): void
    {
        $client = new ScriptedLlmClient([
            [LlmEvent::toolCall(new LlmToolCall(id: 'tc_1', name: 'echo', arguments: [])), LlmEvent::turnEnd('tool_use')],
            [LlmEvent::textDelta('désolé'), LlmEvent::turnEnd('end_turn')],
        ]);
        $registry = new ToolRegistry();
        $registry->register(new RecordingEchoTool(failing: true));

        $events = $this->runTurn($client, $registry);

        $this->assertSame('done', end($events)->type);

        $secondCallMessages = $client->receivedMessages[1];
        $lastMessage = end($secondCallMessages);
        $this->assertSame('tool', $lastMessage->role);
        $this->assertArrayHasKey('error', $lastMessage->toolResult);
    }

    public function testMaxIterationsGuard(): void
    {
        $client = new ScriptedLlmClient([
            [LlmEvent::toolCall(new LlmToolCall(id: 'tc_loop', name: 'echo', arguments: ['text' => 'again'])), LlmEvent::turnEnd('tool_use')],
        ]);
        $registry = new ToolRegistry();
        $registry->register(new RecordingEchoTool());

        $events = $this->runTurn($client, $registry);

        $this->assertSame('error', end($events)->type);
        $this->assertSame(5, $client->callCount);
    }
}
