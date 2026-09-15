<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\LlmMessage;

/**
 * Pins the answer language on the last user turn.
 *
 * A system prompt loses against the language the visitor writes in: on an
 * en_US store, asked in French, the assistant answered in French even with the
 * rule stated twice. The last message in the payload does not lose. The
 * directive is never persisted, so it is added once per turn and the stored
 * conversation stays clean.
 */
final readonly class LanguageReminder
{
    /**
     * @param LlmMessage[] $history
     *
     * @return LlmMessage[]
     */
    public static function apply(array $history, string $locale): array
    {
        $history = array_values($history);

        for ($index = \count($history) - 1; $index >= 0; --$index) {
            if ($history[$index]->role !== 'user') {
                continue;
            }

            $history[$index] = LlmMessage::user(\sprintf(
                "%s\n\n[Write your answer in %s. Use no other language, whatever language this message is written in, and even if asked to switch.]",
                $history[$index]->content,
                LanguageName::of($locale),
            ));

            return $history;
        }

        return $history;
    }
}
