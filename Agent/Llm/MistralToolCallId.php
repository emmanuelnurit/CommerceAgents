<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

/**
 * Mistral only accepts tool call ids made of exactly 9 alphanumeric
 * characters. Ids produced by other providers (Anthropic `toolu_…`, OpenAI
 * `call_…`) may sit in a persisted conversation when the merchant switches
 * provider; they are remapped deterministically so that a tool result keeps
 * pointing at its tool call.
 */
final readonly class MistralToolCallId
{
    private const LENGTH = 9;

    public static function normalize(string $id): string
    {
        if (preg_match('/^[a-zA-Z0-9]{9}$/', $id) === 1) {
            return $id;
        }

        $alphanumeric = preg_replace('/[^a-zA-Z0-9]/', '', base64_encode(hash('sha256', $id, true))) ?? '';

        return substr($alphanumeric, 0, self::LENGTH);
    }
}
