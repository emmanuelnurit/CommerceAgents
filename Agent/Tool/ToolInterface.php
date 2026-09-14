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

    /**
     * Capability group (one of the Capability constants) an agent definition
     * must be granted for this tool to be exposed to it.
     */
    public function getRequiredCapability(): string;

    public function isAllowed(ToolContext $ctx): bool;

    public function execute(array $args, ToolContext $ctx): array;
}
