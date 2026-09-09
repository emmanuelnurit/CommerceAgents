<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Llm;

final readonly class LlmToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {
    }
}
