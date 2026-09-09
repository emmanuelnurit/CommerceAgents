<?php

declare(strict_types=1);

namespace CommerceAgents\Agent;

use CommerceAgents\Agent\Llm\LlmToolCall;

final readonly class AgentEvent
{
    public const TEXT_DELTA = 'text_delta';
    public const TOOL_CALL = 'tool_call';
    public const TOOL_RESULT = 'tool_result';
    public const DONE = 'done';
    public const ERROR = 'error';

    public function __construct(
        public string $type,
        public array $payload = [],
    ) {
    }

    public static function textDelta(string $text): self
    {
        return new self(type: self::TEXT_DELTA, payload: ['text' => $text]);
    }

    public static function toolCall(LlmToolCall $toolCall): self
    {
        return new self(type: self::TOOL_CALL, payload: [
            'id' => $toolCall->id,
            'name' => $toolCall->name,
            'arguments' => $toolCall->arguments,
        ]);
    }

    public static function toolResult(string $toolCallId, string $toolName, array $result): self
    {
        return new self(type: self::TOOL_RESULT, payload: [
            'id' => $toolCallId,
            'name' => $toolName,
            'result' => $result,
        ]);
    }

    public static function done(): self
    {
        return new self(type: self::DONE);
    }

    public static function error(string $message): self
    {
        return new self(type: self::ERROR, payload: ['message' => $message]);
    }
}
