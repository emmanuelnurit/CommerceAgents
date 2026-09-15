<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Agent\Tool\Capability;
use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\AgentPresets;

/**
 * Stub for the daily-sales-summary specialty (MYO-328 lot 0). Recognized by
 * preset_code, or by its capability signature (analytics.read + orders.read)
 * when preset_code is unset -- unique among the 5 presets, see the MYO-327
 * decision comment.
 */
final readonly class DailySalesSummaryResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::DAILY_SALES_SUMMARY
            || (\in_array(Capability::ANALYTICS_READ, $capabilities, true) && \in_array(Capability::ORDERS_READ, $capabilities, true));
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_specialty_stub.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        return ['specialtyTitle' => AgentPresets::find(AgentPresets::DAILY_SALES_SUMMARY)['title']];
    }
}
