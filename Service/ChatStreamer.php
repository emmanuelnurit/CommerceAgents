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
        private SessionFreezer $sessionFreezer,
    ) {
    }

    /**
     * Streams a full agent turn as live SSE, persisting assistant messages and
     * tool calls along the way.
     *
     * The request session is frozen (in-memory copy) before streaming starts:
     * the shopping tools read the session (currency, cart, tax country) while
     * the body is already streaming, and any native session restart at that
     * point would abort the turn. Callers must warm up whatever needs to
     * persist in the real session (session cart) BEFORE calling stream().
     * A broken turn still leaves a replayable history thanks to
     * HistorySanitizer on the read side.
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
        $this->sessionFreezer->freeze();

        $response = new StreamedResponse(function () use ($runtime, $history, $system, $toolContext, $llmConfig, $conversation): void {
            $assistantText = '';
            // Usage reported by the provider for the LLM call in progress; it is
            // credited to the next persisted assistant message (the runtime emits
            // it before the tool calls of the same call).
            $pendingTokensIn = 0;
            $pendingTokensOut = 0;

            try {
                foreach ($runtime->runTurn($history, $system, $toolContext, $llmConfig) as $event) {
                    if ($event->type === AgentEvent::TEXT_DELTA) {
                        $assistantText .= $event->payload['text'];
                    } elseif ($event->type === AgentEvent::USAGE) {
                        $pendingTokensIn += $event->payload['input_tokens'];
                        $pendingTokensOut += $event->payload['output_tokens'];
                    } elseif ($event->type === AgentEvent::TOOL_CALL) {
                        $this->conversationService->appendMessage(
                            $conversation->getId(),
                            'assistant',
                            $assistantText,
                            [['id' => $event->payload['id'], 'name' => $event->payload['name'], 'arguments' => $event->payload['arguments']]],
                            $pendingTokensIn,
                            $pendingTokensOut,
                        );
                        $assistantText = '';
                        $pendingTokensIn = 0;
                        $pendingTokensOut = 0;
                    } elseif ($event->type === AgentEvent::TOOL_RESULT) {
                        $this->conversationService->appendMessage(
                            $conversation->getId(),
                            'tool',
                            json_encode($event->payload['result'], \JSON_THROW_ON_ERROR),
                            ['tool_call_id' => $event->payload['id']],
                        );
                    }

                    $this->emit($event->type, $event->payload);
                }
            } catch (\Throwable $exception) {
                $this->emit(AgentEvent::ERROR, ['message' => 'The assistant hit an unexpected error: '.$exception->getMessage()]);
            }

            if ($assistantText !== '') {
                $this->conversationService->appendMessage($conversation->getId(), 'assistant', $assistantText, null, $pendingTokensIn, $pendingTokensOut);
            } elseif ($pendingTokensIn > 0 || $pendingTokensOut > 0) {
                $this->conversationService->addTokensToLatestAssistantMessage($conversation->getId(), $pendingTokensIn, $pendingTokensOut);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function emit(string $type, array $payload): void
    {
        echo 'event: '.$type."\n";
        echo 'data: '.json_encode($payload, \JSON_THROW_ON_ERROR)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
