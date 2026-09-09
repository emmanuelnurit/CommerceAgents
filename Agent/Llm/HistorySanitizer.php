<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

/**
 * Removes broken tool exchanges from a persisted conversation history so it can
 * be safely replayed to a provider. A turn that dies after persisting a
 * `tool_use` but before its `tool_result` (or vice-versa) would otherwise make
 * every later turn fail with "tool_use without tool_result".
 */
final readonly class HistorySanitizer
{
    /**
     * @param LlmMessage[] $messages
     *
     * @return LlmMessage[]
     */
    public static function sanitize(array $messages): array
    {
        $toolUseIds = [];
        $toolResultIds = [];
        foreach ($messages as $message) {
            if ($message->role === 'tool' && $message->toolCallId !== null) {
                $toolResultIds[$message->toolCallId] = true;
            }
            if ($message->role === 'assistant') {
                foreach ($message->toolCalls as $toolCall) {
                    $toolUseIds[$toolCall->id] = true;
                }
            }
        }

        $sanitized = [];
        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                if ($message->toolCallId !== null && isset($toolUseIds[$message->toolCallId])) {
                    $sanitized[] = $message;
                }
                continue;
            }

            if ($message->role === 'assistant' && $message->toolCalls !== []) {
                $keptToolCalls = array_values(array_filter(
                    $message->toolCalls,
                    static fn (LlmToolCall $toolCall): bool => isset($toolResultIds[$toolCall->id]),
                ));

                if (\count($keptToolCalls) === \count($message->toolCalls)) {
                    $sanitized[] = $message;
                    continue;
                }

                if ($keptToolCalls === [] && $message->content === '') {
                    continue;
                }

                $sanitized[] = LlmMessage::assistant($message->content, $keptToolCalls);
                continue;
            }

            $sanitized[] = $message;
        }

        return $sanitized;
    }
}
