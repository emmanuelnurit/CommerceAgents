<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

interface LlmClientInterface
{
    /**
     * @param LlmMessage[] $messages
     * @param array[]      $toolSpecs normalized specs {name, description, input_schema}
     *
     * @return \Generator<LlmEvent>
     */
    public function streamChat(array $messages, array $toolSpecs, string $system, LlmConfig $config): \Generator;
}
