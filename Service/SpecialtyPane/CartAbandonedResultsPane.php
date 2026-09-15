<?php

declare(strict_types=1);

namespace CommerceAgents\Service\SpecialtyPane;

use CommerceAgents\Model\AgentDefinition;
use CommerceAgents\Service\AgentPresets;

/**
 * Stub for the cart-abandoned specialty (MYO-328 lot 0). Only recognized by
 * an exact preset_code match: its capability set (catalog.read,
 * customer.read) is identical to welcome_new_customer's, so a capability
 * fallback would guess wrong -- see the MYO-327 decision comment.
 */
final readonly class CartAbandonedResultsPane implements SpecificSpecialtyResultsPaneInterface
{
    public function supports(?string $presetCode, array $capabilities): bool
    {
        return $presetCode === AgentPresets::CART_ABANDONED;
    }

    public function getTemplate(): string
    {
        return '@CommerceAgentsModule/backOffice/default-twig/agents/results/_specialty_stub.html.twig';
    }

    public function getViewData(AgentDefinition $definition): array
    {
        return ['specialtyTitle' => AgentPresets::find(AgentPresets::CART_ABANDONED)['title']];
    }
}
