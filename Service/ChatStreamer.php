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
     * Runs the full agent turn and returns it as an SSE response.
     *
     * The turn is computed up-front, before any output is emitted, because the
     * shopping tools read and write the Thelia session (currency, cart, tax
     * country) through services like TaxEngine::getDeliveryCountry(). Touching
     * the session once the response body has started streaming raises
     * "Failed to start the session because headers have already been sent" and
     * aborts the turn, leaving a half-written tool exchange in the history.
     * Buffering the frames keeps every session access inside the controller
     * phase; only the pre-rendered frames are sent while streaming.
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
        $frames = $this->runTurn($runtime, $history, $system, $toolContext, $llmConfig, $conversation);

        $response = new StreamedResponse(function () use ($frames): void {
            foreach ($frames as $frame) {
                echo $frame;

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * @param \CommerceAgents\Agent\Llm\LlmMessage[] $history
     *
     * @return string[] pre-rendered SSE frames
     */
    private function runTurn(
        AgentRuntime $runtime,
        array $history,
        string $system,
        ToolContext $toolContext,
        LlmConfig $llmConfig,
        AgentConversation $conversation,
    ): array {
        $frames = [];
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

            $frames[] = 'event: '.$event->type."\n"
                .'data: '.json_encode($event->payload, \JSON_THROW_ON_ERROR)."\n\n";
        }

        if ($assistantText !== '') {
            $this->conversationService->appendMessage($conversation->getId(), 'assistant', $assistantText);
        }

        return $frames;
    }
}
