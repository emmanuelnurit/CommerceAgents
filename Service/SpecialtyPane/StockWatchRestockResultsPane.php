<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\AgentPresets;

/**
 * Stub for the stock-watch-restock specialty (MYO-328 lot 0). Recognized by
 * preset_code, or by inventory.write when preset_code is unset -- unique
 * among the 5 presets, see the MYO-327 decision comment.
 */
final readonly class StockWatchRestockResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::STOCK_WATCH_RESTOCK
            || \in_array(Capability::INVENTORY_WRITE, $capabilities, true);
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_specialty_stub.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        return ['specialtyTitle' => AgentPresets::find(AgentPresets::STOCK_WATCH_RESTOCK)['title']];
    }
}
