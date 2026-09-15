<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\AgentPresets;

/**
 * Stub for the welcome-new-customer specialty (MYO-328 lot 0). Only
 * recognized by an exact preset_code match, for the same reason as
 * CartAbandonedResultsPane: identical capability set, ambiguous otherwise.
 */
final readonly class WelcomeNewCustomerResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::WELCOME_NEW_CUSTOMER;
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_specialty_stub.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        return ['specialtyTitle' => AgentPresets::find(AgentPresets::WELCOME_NEW_CUSTOMER)['title']];
    }
}
