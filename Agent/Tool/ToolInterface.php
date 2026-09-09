<?php

declare(strict_types=1);

namespace CommerceAgents\Agent\Tool;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema describing the tool arguments.
     */
    public function getInputSchema(): array;

    public function isAllowed(ToolContext $ctx): bool;

    public function execute(array $args, ToolContext $ctx): array;
}
