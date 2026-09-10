<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\Llm\HistorySanitizer;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Llm\LlmToolCall;
use CommerceAgents\Model\AgentConversation;
use CommerceAgents\Model\AgentConversationQuery;
use CommerceAgents\Model\AgentMessage;
use CommerceAgents\Model\AgentMessageQuery;
use Propel\Runtime\ActiveQuery\Criteria;

final readonly class ConversationService
{
    public function getOrCreate(string $type, string $sessionRef, ?int $customerId, string $locale, ?int $adminId = null): AgentConversation
    {
        $conversation = AgentConversationQuery::create()
            ->filterByType($type)
            ->filterBySessionRef($sessionRef)
            ->orderById(Criteria::DESC)
            ->findOne();

        if ($conversation !== null) {
            return $conversation;
        }

        $conversation = (new AgentConversation())
            ->setType($type)
            ->setSessionRef($sessionRef)
            ->setCustomerId($customerId)
            ->setAdminId($adminId)
            ->setLocale($locale);
        $conversation->save();

        return $conversation;
    }

    /**
     * @param array|null $toolCalls for an assistant message: list of {id, name, arguments};
     *                              for a tool message: {tool_call_id: string}
     */
    public function appendMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls = null,
        int $tokensIn = 0,
        int $tokensOut = 0,
    ): AgentMessage {
        $message = (new AgentMessage())
            ->setConversationId($conversationId)
            ->setRole($role)
            ->setContent($content)
            ->setToolCalls($toolCalls !== null ? json_encode($toolCalls, \JSON_THROW_ON_ERROR) : null)
            ->setTokensIn($tokensIn)
            ->setTokensOut($tokensOut);
        $message->save();

        return $message;
    }

    /**
     * Credits provider usage to the most recent assistant message of the
     * conversation, for turns that end without any new assistant text.
     */
    public function addTokensToLatestAssistantMessage(int $conversationId, int $tokensIn, int $tokensOut): void
    {
        $message = AgentMessageQuery::create()
            ->filterByConversationId($conversationId)
            ->filterByRole('assistant')
            ->orderById(Criteria::DESC)
            ->findOne();

        if ($message === null) {
            return;
        }

        $message
            ->setTokensIn((int) $message->getTokensIn() + $tokensIn)
            ->setTokensOut((int) $message->getTokensOut() + $tokensOut)
            ->save();
    }

    /**
     * @return LlmMessage[]
     */
    public function getHistory(int $conversationId, int $limit = 40): array
    {
        $rows = AgentMessageQuery::create()
            ->filterByConversationId($conversationId)
            ->orderById(Criteria::DESC)
            ->limit($limit)
            ->find()
            ->getData();

        $history = [];
        foreach (array_reverse($rows) as $row) {
            $history[] = $this->toLlmMessage($row);
        }

        return HistorySanitizer::sanitize($history);
    }

    private function toLlmMessage(AgentMessage $message): LlmMessage
    {
        $toolCalls = $message->getToolCalls() !== null
            ? json_decode($message->getToolCalls(), true)
            : null;

        return match ($message->getRole()) {
            'assistant' => LlmMessage::assistant(
                (string) $message->getContent(),
                array_map(
                    static fn (array $call): LlmToolCall => new LlmToolCall(
                        id: $call['id'],
                        name: $call['name'],
                        arguments: $call['arguments'] ?? [],
                    ),
                    $toolCalls ?? [],
                ),
            ),
            'tool' => LlmMessage::toolResult(
                (string) ($toolCalls['tool_call_id'] ?? ''),
                json_decode((string) $message->getContent(), true) ?? [],
            ),
            default => LlmMessage::user((string) $message->getContent()),
        };
    }
}
