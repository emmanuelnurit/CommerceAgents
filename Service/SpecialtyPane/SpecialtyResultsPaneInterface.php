<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Model\AgentDefinition;

/**
 * One "Results" tab renderer per agent specialty (MYO-327/MYO-328 lot 0).
 * SpecialtyResultsPaneRegistry resolves the right implementation for an
 * agent by preset_code first, then by an unambiguous capability signature,
 * falling back to GenericResultsPane -- see the registry for the full
 * resolution order and why capabilities alone cannot distinguish every
 * preset.
 */
interface SpecialtyResultsPaneInterface
{
    /**
     * @param list<string> $capabilities capabilities granted to the agent
     */
    public function supports(?string $presetCode, array $capabilities): bool;

    /**
     * Twig template path (module namespace), rendered with getViewData()'s
     * return value.
     */
    public function getTemplate(): string;

    /**
     * @return array<string, mixed>
     */
    public function getViewData(AgentDefinition $definition): array;
}
