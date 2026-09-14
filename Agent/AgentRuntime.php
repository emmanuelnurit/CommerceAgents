<?php

declare(strict_types=1);

namespace CommerceAgents\Agent;

use CommerceAgents\Agent\Llm\LlmClientInterface;
use CommerceAgents\Agent\Llm\LlmConfig;
use CommerceAgents\Agent\Llm\LlmEvent;
use CommerceAgents\Agent\Llm\LlmMessage;
use CommerceAgents\Agent\Tool\ToolContext;
use CommerceAgents\Agent\Tool\ToolException;
use CommerceAgents\Agent\Tool\ToolRegistry;

final readonly class AgentRuntime
{
    public const DEFAULT_MAX_ITERATIONS = 5;

    public function __construct(
        private LlmClientInterface $llmClient,
        private ToolRegistry $toolRegistry,
        private int $maxIterations = self::DEFAULT_MAX_ITERATIONS,
    ) {
    }

    /**
     * Runs one full conversation turn: calls the LLM, executes requested
     * tools and feeds results back until the model ends its turn.
     *
     * @param LlmMessage[] $messages
     *
     * @return \Generator<AgentEvent>
     */
    public function runTurn(array $messages, string $system, ToolContext $ctx, LlmConfig $config): \Generator
    {
        $toolSpecs = $this->toolRegistry->getToolSpecs($ctx);

        for ($iteration = 0; $iteration < $this->maxIterations; ++$iteration) {
            $assistantText = '';
            $toolCalls = [];
            $stopReason = null;
            $inputTokens = 0;
            $outputTokens = 0;

            foreach ($this->llmClient->streamChat($messages, $toolSpecs, $system, $config) as $event) {
                switch ($event->type) {
                    case LlmEvent::TEXT_DELTA:
                        $assistantText .= $event->text;
                        yield AgentEvent::textDelta($event->text);
                        break;

                    case LlmEvent::TOOL_CALL:
                        $toolCalls[] = $event->toolCall;
                        break;

                    case LlmEvent::TURN_END:
                        $stopReason = $event->stopReason;
                        $inputTokens = $event->inputTokens;
                        $outputTokens = $event->outputTokens;
                        break;

                    case LlmEvent::ERROR:
                        yield AgentEvent::error($event->text);

                        return;
                }
            }

            if ($inputTokens > 0 || $outputTokens > 0) {
                yield AgentEvent::usage($inputTokens, $outputTokens);
            }

            if ($stopReason !== 'tool_use' || $toolCalls === []) {
                yield AgentEvent::done();

                return;
            }

            $messages[] = LlmMessage::assistant($assistantText, $toolCalls);

            foreach ($toolCalls as $toolCall) {
                yield AgentEvent::toolCall($toolCall);

                try {
                    $result = $this->toolRegistry->execute($toolCall->name, $toolCall->arguments, $ctx);
                } catch (ToolException $exception) {
                    $result = ['error' => $exception->getMessage()];
                }

                yield AgentEvent::toolResult($toolCall->id, $toolCall->name, $result);

                $messages[] = LlmMessage::toolResult($toolCall->id, $result);
            }
        }

        yield AgentEvent::error(\sprintf('Agent stopped after %d tool iterations', $this->maxIterations));
    }
}
