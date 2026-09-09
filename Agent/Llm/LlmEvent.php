<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

final readonly class LlmEvent
{
    public const TEXT_DELTA = 'text_delta';
    public const TOOL_CALL = 'tool_call';
    public const TURN_END = 'turn_end';
    public const ERROR = 'error';

    public function __construct(
        public string $type,
        public string $text = '',
        public ?LlmToolCall $toolCall = null,
        public ?string $stopReason = null,
    ) {
    }

    public static function textDelta(string $text): self
    {
        return new self(type: self::TEXT_DELTA, text: $text);
    }

    public static function toolCall(LlmToolCall $toolCall): self
    {
        return new self(type: self::TOOL_CALL, toolCall: $toolCall);
    }

    public static function turnEnd(?string $stopReason): self
    {
        return new self(type: self::TURN_END, stopReason: $stopReason);
    }

    public static function error(string $message): self
    {
        return new self(type: self::ERROR, text: $message);
    }
}
