<?php

declare(strict_types=1);

namespace CommerceAgents\Service;

use CommerceAgents\Agent\AgentEvent;
use CommerceAgents\Agent\AgentRuntime;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Model\AgentConversation;
use Symfony\Component\HttpFoundation\StreamedResponse;

final readonly class ChatStreamer
{
    public function __construct(
        private ConversationService $conversationService,
    ) {
    }

    /**
     * Streams a full agent turn as SSE, persisting assistant messages and
     * tool calls/results along the way.
     *
     * @param \CommerceAgents\Agent\Llm\LlmMessage[] $history
     */
    public function stream(
        AgentRuntime $runtime,
        array $history,
        string $system,
        ToolContext $toolContext,
        LlmConfig $llmConfig,
        AgentConversation $conversation,
    ): StreamedResponse {
        $response = new StreamedResponse(function () use ($runtime, $history, $system, $toolContext, $llmConfig, $conversation): void {
            $assistantText = '';

            foreach ($runtime->runTurn($history, $system, $toolContext, $llmConfig) as $event) {
                if ($event->type === AgentEvent::TEXT_DELTA) {
                    $assistantText .= $event->payload['text'];
                } elseif ($event->type === AgentEvent::TOOL_CALL) {
                    $this->conversationService->appendMessage(
                        $conversation->getId(),
                        'assistant',
                        $assistantText,
                        [['id' => $event->payload['id'], 'name' => $event->payload['name'], 'arguments' => $event->payload['arguments']]],
                    );
                    $assistantText = '';
                } elseif ($event->type === AgentEvent::TOOL_RESULT) {
                    $this->conversationService->appendMessage(
                        $conversation->getId(),
                        'tool',
                        json_encode($event->payload['result'], \JSON_THROW_ON_ERROR),
                        ['tool_call_id' => $event->payload['id']],
                    );
                }

                echo 'event: '.$event->type."\n";
                echo 'data: '.json_encode($event->payload, \JSON_THROW_ON_ERROR)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }

            if ($assistantText !== '') {
                $this->conversationService->appendMessage($conversation->getId(), 'assistant', $assistantText);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
