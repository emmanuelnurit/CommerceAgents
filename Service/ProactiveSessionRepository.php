<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Model\AgentConversation;

/**
 * Persists proactive-solicitation state on the existing AgentConversation
 * row rather than a new table (plan MYO-236 / MYO-246 point 3).
 */
final readonly class ProactiveSessionRepository
{
    public function stateOf(AgentConversation $conversation): ProactiveSessionState
    {
        $lastPromptedAt = $conversation->getProactiveLastPromptedAt();

        return new ProactiveSessionState(
            dismissed: (bool) $conversation->getProactiveDismissed(),
            promptCount: (int) $conversation->getProactivePromptCount(),
            lastPromptedAt: $lastPromptedAt instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($lastPromptedAt) : null,
            triggeredScenarios: self::decodeScenarios($conversation->getProactiveScenarios()),
        );
    }

    public function recordPrompt(AgentConversation $conversation, string $scenario, \DateTimeImmutable $now): void
    {
        $scenarios = self::decodeScenarios($conversation->getProactiveScenarios());
        $scenarios[] = $scenario;

        $conversation
            ->setProactivePromptCount($conversation->getProactivePromptCount() + 1)
            ->setProactiveLastPromptedAt($now)
            ->setProactiveScenarios(json_encode(array_values(array_unique($scenarios)), \JSON_THROW_ON_ERROR))
            ->save();
    }

    public function recordDismissal(AgentConversation $conversation): void
    {
        $conversation->setProactiveDismissed(1)->save();
    }

    /**
     * @return list<string>
     */
    private static function decodeScenarios(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
