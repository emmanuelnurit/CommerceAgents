<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

final readonly class LlmMessage
{
    /**
     * @param LlmToolCall[] $toolCalls
     */
    public function __construct(
        public string $role,
        public string $content = '',
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?array $toolResult = null,
    ) {
    }

    public static function user(string $content): self
    {
        return new self(role: 'user', content: $content);
    }

    /**
     * @param LlmToolCall[] $toolCalls
     */
    public static function assistant(string $content, array $toolCalls = []): self
    {
        return new self(role: 'assistant', content: $content, toolCalls: $toolCalls);
    }

    public static function toolResult(string $toolCallId, array $result): self
    {
        return new self(role: 'tool', toolCallId: $toolCallId, toolResult: $result);
    }
}
