<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

/**
 * Converts the provider-neutral history into the OpenAI chat-completions
 * message format shared by OpenAI, OpenRouter, Mistral and friends.
 */
final readonly class OpenAiMessageConverter
{
    /**
     * @param LlmMessage[]                  $messages
     * @param \Closure(string): string|null $toolCallIdMapper provider-specific id normalization
     */
    public static function convert(array $messages, string $system, ?\Closure $toolCallIdMapper = null): array
    {
        $mapId = $toolCallIdMapper ?? static fn (string $id): string => $id;

        $converted = [];
        if ($system !== '') {
            $converted[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $message) {
            if ($message->role === 'tool') {
                $converted[] = [
                    'role' => 'tool',
                    'tool_call_id' => $mapId((string) $message->toolCallId),
                    'content' => json_encode($message->toolResult, \JSON_THROW_ON_ERROR),
                ];
                continue;
            }

            if ($message->role === 'assistant' && $message->toolCalls !== []) {
                $converted[] = [
                    'role' => 'assistant',
                    'content' => $message->content !== '' ? $message->content : null,
                    'tool_calls' => array_map(
                        static fn (LlmToolCall $toolCall): array => [
                            'id' => $mapId($toolCall->id),
                            'type' => 'function',
                            'function' => [
                                'name' => $toolCall->name,
                                'arguments' => json_encode($toolCall->arguments === [] ? new \stdClass() : $toolCall->arguments, \JSON_THROW_ON_ERROR),
                            ],
                        ],
                        $message->toolCalls,
                    ),
                ];
                continue;
            }

            $converted[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $converted;
    }

    /**
     * @param array[] $toolSpecs normalized specs {name, description, input_schema}
     */
    public static function convertTools(array $toolSpecs): array
    {
        return array_map(
            static fn (array $spec): array => [
                'type' => 'function',
                'function' => [
                    'name' => $spec['name'],
                    'description' => $spec['description'],
                    'parameters' => $spec['input_schema'],
                ],
            ],
            $toolSpecs,
        );
    }
}
